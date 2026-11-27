<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Backups\Jobs\RunBackupJob;
use Nabilet\Modules\Backups\Services\YandexDiskSettings;
use Nabilet\Modules\Updater\Jobs\RunLocalBackupJob;
use Nabilet\Core\Errors\AppError;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\System\Services\HealthProbe;
use Nabilet\Modules\Updater\Domain\ReleaseManifest;
use Nabilet\Modules\Updater\Domain\UpdateStatus;
use Nabilet\Modules\Updater\Models\SystemUpdate;

/**
 * Конечный автомат обновления: один тик = один переход.
 *
 * Два правила, без которых механизм ломается на шаред-хостинге:
 *   1. Тяжёлая работа НЕ делается в HTTP. Запрос только создаёт запись и
 *      возвращает 202; скачивание, распаковка, подмена и миграции идут в cron.
 *   2. После подмены файлов текущий PHP-процесс ещё держит старый код в памяти.
 *      Поэтому `migrate` и очистка кеша выполняются СЛЕДУЮЩИМ тиком — новым
 *      процессом с новым autoload-ом.
 */
final class UpdateOrchestrator
{
    public function __construct(
        private readonly UpdateStorage $storage,
        private readonly UpdateJournal $journal,
        private readonly UpdateFeed $feed,
        private readonly ManifestVerifier $verifier,
        private readonly ArchiveDownloader $downloader,
        private readonly ReleaseExtractor $extractor,
        private readonly UpdatePreflight $preflight,
        private readonly UpdateApplier $applier,
        private readonly HealthProbe $probe,
        private readonly YandexDiskSettings $backups,
        private readonly LocalBackupStatus $localBackup,
    ) {
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        return $this->feed->check();
    }

    /**
     * @param array{version: string, source: string, file?: string, checksum?: string} $data
     */
    public function start(array $data, int $actorId): SystemUpdate
    {
        // Проверка «нет активного обновления» и создание записи обязаны быть
        // одной критической секцией: два администратора могли одновременно
        // пройти проверку и запустить два обновления.
        $result = $this->storage->withGlobalLock(fn (): SystemUpdate => $this->startLocked($data, $actorId));
        if ($result === null) {
            throw new ConflictError('Обновление уже выполняется.', 'UPDATE_IN_PROGRESS');
        }

        return $result;
    }

    /** @param array{version: string, source: string, file?: string, checksum?: string} $data */
    private function startLocked(array $data, int $actorId): SystemUpdate
    {
        if (SystemUpdate::query()->whereIn('status', UpdateStatus::ACTIVE)->exists()) {
            throw new ConflictError('Обновление уже выполняется.', 'UPDATE_IN_PROGRESS');
        }

        $this->storage->ensureRecoveryScript();

        $current = (string) config('nabilet.version', '0.0.0');
        $version = (string) $data['version'];
        $source = (string) $data['source'];

        if ($source === SystemUpdate::SOURCE_FEED) {
            $manifest = $this->feed->fetch();
            $this->verifier->verify($manifest);
            if (! hash_equals($manifest->version, $version) || ! $manifest->appliesTo($current)) {
                throw new ValidationError(['version' => ['Выбранный релиз не применим к текущей версии.']]);
            }
        } elseif ($source === SystemUpdate::SOURCE_UPLOAD) {
            $file = basename((string) ($data['file'] ?? ''));
            $checksum = strtolower((string) ($data['checksum'] ?? ''));
            if ($file === '' || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                throw new ValidationError(['file' => ['Укажите архив и корректный SHA-256.']]);
            }
            $path = $this->storage->uploads() . '/' . $file;
            if (! is_file($path)) {
                throw new ValidationError(['file' => ['Загруженный архив не найден.']]);
            }
            $this->downloader->verifyHash($path, $checksum);
        } else {
            throw new ValidationError(['source' => ['Неизвестный источник обновления.']]);
        }

        $this->storage->ensureDirectories(0);

        $update = SystemUpdate::query()->create([
            'from_version' => $current,
            'to_version' => $version,
            'source' => $source,
            'status' => UpdateStatus::PREPARING,
            'step' => 'created',
            'artifact' => $source === SystemUpdate::SOURCE_FEED ? $manifest->artifact : $file,
            'artifact_sha256' => $source === SystemUpdate::SOURCE_FEED ? $manifest->sha256 : $checksum,
            'backup_status' => null,
            'actor_id' => $actorId,
            'started_at' => now(),
        ]);

        $this->journal->begin((int) $update->id, [
            'from_version' => $current,
            'to_version' => $version,
            'source' => $source,
            'artifact_sha256' => (string) $update->artifact_sha256,
        ]);

        $backup = $this->preflight->backupState();
        if ($backup['needs_run']) {
            if ($backup['disk_connected']) {
                RunBackupJob::dispatch('full', $actorId);
                $update->backup_status = 'queued';
                $update->step = 'waiting_backup';
            } elseif ((bool) config('updates.allow_local_backup', true)) {
                // Диск не подключён — делаем локальный полный бэкап в очередь,
                // чтобы mysqldump не выполнялся внутри HTTP-запроса.
                $this->localBackup->recordQueued((int) $update->id);
                RunLocalBackupJob::dispatch((int) $update->id, $actorId);
                $update->backup_status = 'queued_local';
                $update->step = 'waiting_backup';
            } else {
                $update->status = UpdateStatus::FAILED;
                $update->step = 'error';
                $update->error = 'Нет свежей полной копии, локальные бэкапы запрещены настройками.';
                $update->finished_at = now();
            }
        }

        $update->save();
        $this->writeState($update);

        if ((string) $update->status === UpdateStatus::FAILED) {
            Log::channel('alerts')->warning('self-update: старт заблокирован', [
                'update_id' => $update->id,
                'message' => $update->error,
            ]);

            return $update;
        }

        Log::channel('security')->info('Обновление системы запущено', [
            'update_id' => $update->id,
            'from_version' => $current,
            'to_version' => $version,
            'source' => $source,
            'user_id' => $actorId,
        ]);

        return $update;
    }

    /**
     * Один шаг автомата из крона.
     *
     * @return array{handled: bool, update_id?: int, status?: string, message?: string}
     */
    public function tick(): array
    {
        // Один тик на весь сайт: параллельные schedule:run не должны оба
        // захватывать один и тот же шаг или крутить откат.
        $result = $this->storage->withGlobalLock(function (): array {
            $this->storage->touchHeartbeat();
            $this->recoverInterrupted();

            return $this->tickLocked();
        });

        return $result ?? ['handled' => false, 'message' => 'Другое обновление уже выполняется.'];
    }

    /**
     * Обновление, прерванное SIGKILL/перезапуском PHP, оставалось в APPLYING
     * навсегда: блокировка истекала, а статус — нет. Переводим его в состояние,
     * из которого планировщик сможет инициировать откат.
     */
    private function recoverInterrupted(): void
    {
        $staleMinutes = max(5, (int) config('updates.lock_stale_minutes', 15));
        // `locked_at` снимается на границе тиков по дизайну (каждый тик — новый
        // процесс), поэтому его отсутствие НЕ признак прерывания. Истинный
        // признак — давность последнего сохранения статуса: нормальный тик
        // обновляет `updated_at`, а SIGKILL оставляет старую метку.
        $stale = SystemUpdate::query()
            ->whereIn('status', [UpdateStatus::APPLYING, UpdateStatus::MIGRATING, UpdateStatus::VERIFYING])
            ->where('updated_at', '<=', now()->subMinutes($staleMinutes))
            ->orderBy('id')
            ->get();

        foreach ($stale as $update) {
            /** @var SystemUpdate $update */
            if (! UpdateStatus::canTransition((string) $update->status, UpdateStatus::FAILED_AFTER_SWAP)) {
                continue;
            }
            $update->status = UpdateStatus::FAILED_AFTER_SWAP;
            $update->step = 'interrupted_by_process_exit';
            $update->error = 'Обновление прервано завершением процесса; требуется откат.';
            $update->finished_at = now();
            $update->save();
            $this->writeState($update);
            Log::channel('alerts')->warning('self-update: обнаружено прерванное обновление', ['update_id' => $update->id]);
        }
    }

    /** @return array{handled: bool, update_id?: int, status?: string, message?: string} */
    private function tickLocked(): array
    {
        $staleMinutes = max(5, (int) config('updates.lock_stale_minutes', 15));
        /** @var SystemUpdate|null $update */
        $update = DB::transaction(function () use ($staleMinutes): ?SystemUpdate {
            /** @var SystemUpdate|null $candidate */
            $candidate = SystemUpdate::query()
                ->whereIn('status', UpdateStatus::ACTIVE)
                ->where(static function ($q) use ($staleMinutes): void {
                    $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subMinutes($staleMinutes));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($candidate !== null) {
                $candidate->locked_at = now();
                $candidate->save();
            }

            return $candidate;
        });

        if ($update === null) {
            return $this->autoRollbackStuck();
        }

        try {
            $result = match ((string) $update->status) {
                UpdateStatus::PREPARING => $this->prepare($update),
                UpdateStatus::READY => $this->apply($update),
                UpdateStatus::MIGRATING => $this->migrate($update),
                UpdateStatus::VERIFYING => $this->verify($update),
                UpdateStatus::ROLLING_BACK => $this->rollBack($update),
                default => ['handled' => false],
            };
        } catch (\Throwable $e) {
            report($e);
            $afterSwap = in_array((string) $update->status, [
                UpdateStatus::APPLYING,
                UpdateStatus::MIGRATING,
                UpdateStatus::VERIFYING,
            ], true);
            $this->fail($update, $e->getMessage(), $afterSwap);

            $result = ['handled' => true, 'status' => (string) $update->status, 'message' => $e->getMessage()];
        } finally {
            $update->locked_at = null;
            $update->save();
        }

        return $result + ['update_id' => (int) $update->id];
    }

    /**
     * Если после замены файлов что-то пошло не так, сайт остаётся в режиме
     * обслуживания — а значит, админка с кнопкой «Откатить» тоже недоступна.
     * Поэтому по истечении таймаута откат инициирует сам планировщик.
     *
     * @return array{handled: bool, update_id?: int, status?: string, message?: string}
     */
    private function autoRollbackStuck(): array
    {
        $minutes = (int) config('updates.auto_rollback_after_minutes', 10);
        if ($minutes <= 0) {
            return ['handled' => false];
        }

        /** @var SystemUpdate|null $stuck */
        $stuck = SystemUpdate::query()
            ->where('status', UpdateStatus::FAILED_AFTER_SWAP)
            ->where('finished_at', '<=', now()->subMinutes($minutes))
            ->orderByDesc('id')
            ->first();

        if ($stuck === null) {
            return ['handled' => false];
        }

        try {
            // Автооткат обязан проходить те же проверки, что и ручной: иначе
            // он снял бы код до устаревшей версии при уже применённых миграциях.
            $this->assertRollbackAllowed($stuck);
        } catch (ConflictError $e) {
            // Режим обслуживания НЕ снимаем: совместимость кода и БД не подтверждена.
            Log::channel('alerts')->error('self-update: автоматический откат заблокирован', [
                'update_id' => $stuck->id,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            return ['handled' => false, 'update_id' => (int) $stuck->id, 'status' => (string) $stuck->status, 'message' => $e->getMessage()];
        }

        $this->transition($stuck, UpdateStatus::ROLLING_BACK, 'auto_rollback');

        Log::channel('alerts')->warning('self-update: автоматический откат после ошибки', [
            'update_id' => $stuck->id,
        ]);

        return [
            'handled' => true,
            'update_id' => (int) $stuck->id,
            'status' => UpdateStatus::ROLLING_BACK,
            'message' => 'Автоматический откат после таймаута',
        ];
    }

    public function cancel(int $updateId): void
    {
        $this->storage->withGlobalLock(function () use ($updateId): void {
            $this->cancelLocked($updateId);
        });
    }

    private function cancelLocked(int $updateId): void
    {
        /** @var SystemUpdate $update */
        $update = SystemUpdate::query()->findOrFail($updateId);
        if (! in_array((string) $update->status, [UpdateStatus::PREPARING, UpdateStatus::READY], true)) {
            throw new ConflictError('Обновление уже нельзя отменить.', 'UPDATE_NOT_CANCELLABLE');
        }

        $this->transition($update, UpdateStatus::CANCELLED, 'cancelled');
        $update->finished_at = now();
        $update->save();
        $this->writeState($update);

        Log::channel('security')->info('Обновление системы отменено', ['update_id' => $updateId]);
    }

    /**
     * Единая проверка допустимости отката для ручного, автоматического и
     * аварийного контуров. Раньше autoRollbackStuck обходил её и мог откатить
     * файлы после применённых миграций или поверх более новой версии.
     */
    private function assertRollbackAllowed(SystemUpdate $update): void
    {
        if (! in_array((string) $update->status, [UpdateStatus::DONE, UpdateStatus::FAILED_AFTER_SWAP], true)) {
            throw new ConflictError('Откат доступен только после обновления или ошибки после замены файлов.', 'UPDATE_NOT_ROLLBACKABLE');
        }

        $latestId = (int) SystemUpdate::query()
            ->whereIn('status', [UpdateStatus::DONE, UpdateStatus::FAILED_AFTER_SWAP, UpdateStatus::ROLLED_BACK])
            ->max('id');
        if ((int) $update->id !== $latestId) {
            throw new ConflictError(
                'Нельзя откатить устаревшее обновление: после него были установлены другие версии.',
                'UPDATE_STALE_ROLLBACK',
                ['update_id' => (int) $update->id, 'latest_update_id' => $latestId],
            );
        }

        $state = $this->journal->migrationState((int) $update->id);
        if ($state !== 'not_started') {
            throw new ConflictError(
                'Миграции находятся в состоянии ' . $state . '. Откат файлов возможен только после ручного восстановления БД из проверенной копии.',
                'UPDATE_ROLLBACK_REQUIRES_DB_RESTORE',
                ['update_id' => (int) $update->id, 'migration_state' => $state],
            );
        }

        if (SystemUpdate::query()->whereIn('status', UpdateStatus::ACTIVE)->exists()) {
            throw new ConflictError('Другое обновление уже выполняется.', 'UPDATE_IN_PROGRESS');
        }
    }

    /** Поставить откат в очередь тика (не выполнять в HTTP-запросе). */
    public function requestRollback(int $updateId): SystemUpdate
    {
        $result = $this->storage->withGlobalLock(fn (): SystemUpdate => $this->requestRollbackLocked($updateId));
        if ($result === null) {
            throw new ConflictError('Обновление уже выполняется.', 'UPDATE_IN_PROGRESS');
        }

        return $result;
    }

    private function requestRollbackLocked(int $updateId): SystemUpdate
    {
        /** @var SystemUpdate $update */
        $update = SystemUpdate::query()->findOrFail($updateId);
        $this->assertRollbackAllowed($update);

        $this->transition($update, UpdateStatus::ROLLING_BACK, 'rollback_queued');

        return $update;
    }

    /** @return array{data: list<array<string, mixed>>} */
    public function history(int $limit = 20): array
    {
        $rows = SystemUpdate::query()->orderByDesc('id')->limit(max(1, min(50, $limit)))->get();
        $data = [];

        foreach ($rows as $u) {
            /** @var SystemUpdate $u */
            $data[] = [
                'id' => (int) $u->id,
                'from_version' => (string) $u->from_version,
                'to_version' => (string) $u->to_version,
                'status' => (string) $u->status,
                'source' => (string) $u->source,
                'started_at' => $u->started_at?->toIso8601String(),
                'finished_at' => $u->finished_at?->toIso8601String(),
                'error' => $u->error,
            ];
        }

        return ['data' => $data];
    }

    /** @return array<string, mixed> */
    private function prepare(SystemUpdate $update): array
    {
        $backup = $this->preflight->backupState();

        if ($backup['needs_run']) {
            if ($backup['fresh']) {
                $update->backup_status = 'success';
                $update->step = 'backup_ready';
                $update->save();
            } else {
                $startedAt = $update->created_at;

                // Локальный бэкап сообщает результат через свой маркер.
                if ($startedAt !== null && $this->localBackup->isFailedFor((int) $update->id, $startedAt)) {
                    $state = $this->localBackup->latest();
                    $this->fail($update, 'Локальный бэкап не удался: ' . (string) ($state['error'] ?? ''), false);

                    return ['handled' => true, 'status' => UpdateStatus::FAILED];
                }

                if ($startedAt !== null && $this->localBackup->isSuccessFor((int) $update->id, $startedAt)) {
                    $update->backup_status = 'success_local';
                    $update->step = 'backup_ready';
                    $update->save();
                } else {
                    // Ветка с Яндекс.Диском: ошибка приходит в last_run.
                    $settings = $this->backups->forAdmin();
                    $lastRun = isset($settings['last_run']) && is_array($settings['last_run']) ? $settings['last_run'] : [];
                    $finishedAt = isset($lastRun['at']) ? (string) $lastRun['at'] : '';
                    $failedAt = $finishedAt !== '' ? strtotime($finishedAt) : false;

                    if ($failedAt !== false && $startedAt !== null
                        && $failedAt >= $startedAt->getTimestamp()
                        && ($lastRun['status'] ?? '') === 'failed') {
                        $this->fail($update, 'Резервное копирование не удалось: ' . (string) ($lastRun['message'] ?? ''), false);

                        return ['handled' => true, 'status' => UpdateStatus::FAILED];
                    }

                    if ($startedAt !== null && $startedAt->lt(now()->subMinutes(30))) {
                        $this->fail($update, 'Резервное копирование не завершилось за 30 минут.', false);

                        return ['handled' => true, 'status' => UpdateStatus::FAILED];
                    }

                    $update->step = 'waiting_backup';
                    $update->save();

                    return ['handled' => true, 'status' => UpdateStatus::PREPARING, 'message' => 'Ожидание резервной копии'];
                }
            }
        }

        $manifest = $this->manifestFor($update);
        if ($update->source === SystemUpdate::SOURCE_FEED) {
            $latest = $this->feed->fetch();
            $this->verifier->verify($latest);
            if (! hash_equals($latest->sha256, (string) $update->artifact_sha256)) {
                throw new AppError('Манифест релиза изменился после запуска обновления.', 'UPDATE_MANIFEST_CHANGED', 409);
            }
        }

        $checks = $this->preflight->check($manifest);
        if (! $checks['ok']) {
            $this->fail($update, implode(' ', $checks['problems']), false);

            return ['handled' => true, 'status' => UpdateStatus::FAILED];
        }

        $this->storage->ensureDirectories((int) $update->id);
        $archive = $this->storage->archive((int) $update->id, (string) $update->artifact);
        $sha = (string) $update->artifact_sha256;

        if ($update->source === SystemUpdate::SOURCE_FEED) {
            $size = $this->downloader->download($this->downloader->artifactUrl((string) $update->artifact), $archive, $sha);
        } else {
            $size = $this->downloader->import(
                $this->storage->uploads() . '/' . basename((string) $update->artifact),
                $archive,
                $sha,
            );
        }

        $unpacked = $this->extractor->extract($archive, (int) $update->id);
        $update->step = sprintf('staged (%d файлов, %.1f МБ)', $unpacked['entries'], round($size / 1024 / 1024, 1));
        $this->transition($update, UpdateStatus::READY, (string) $update->step);

        return ['handled' => true, 'status' => UpdateStatus::READY, 'message' => 'Архив подготовлен'];
    }

    /** @return array{handled: bool, status: string, message: string} */
    private function apply(SystemUpdate $update): array
    {
        // Отдельный статус до подмены нужен, чтобы при сбое тик знал: файлы уже
        // тронуты, и надо оставить сайт в режиме обслуживания до ручного разбора.
        $this->transition($update, UpdateStatus::APPLYING, 'swapping');
        $this->applier->apply((int) $update->id);
        $this->transition($update, UpdateStatus::MIGRATING, 'swap_done');

        return ['handled' => true, 'status' => UpdateStatus::MIGRATING, 'message' => 'Файлы обновлены, применяются миграции'];
    }

    /** @return array{handled: bool, status: string, message: string} */
    private function migrate(SystemUpdate $update): array
    {
        // Фиксируем НАЧАЛО миграций до вызова Artisan: DDL в MySQL не
        // транзакционен, поэтому прерванный процесс оставляет неопределённое
        // состояние, которое нельзя назвать «миграций не было».
        $this->journal->record((int) $update->id, 'migrate', [
            'status' => 'started',
            'from_version' => (string) $update->from_version,
            'to_version' => (string) $update->to_version,
        ]);

        $exit = Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        if ($exit !== 0) {
            $this->journal->record((int) $update->id, 'migrate', [
                'status' => 'uncertain',
                'output' => mb_substr($output, -1000),
            ]);
            Log::error('Миграция при обновлении завершилась с ошибкой', [
                'update_id' => $update->id,
                'output' => mb_substr($output, -4000),
            ]);
            // Режим обслуживания НЕ снимаем: смешанное состояние кода и БД
            // опаснее, чем временная недоступность.
            $this->fail($update, 'Не удалось применить миграции. Подробности — в журнале логов.', true);

            return ['handled' => true, 'status' => UpdateStatus::FAILED_AFTER_SWAP, 'message' => 'Миграции не применены'];
        }

        $this->journal->record((int) $update->id, 'migrate', [
            'status' => 'done',
            'from_version' => (string) $update->from_version,
            'to_version' => (string) $update->to_version,
            'output' => mb_substr($output, -1000),
        ]);
        $this->applier->clearCaches();
        $this->transition($update, UpdateStatus::VERIFYING, 'migrations_done');

        return ['handled' => true, 'status' => UpdateStatus::VERIFYING, 'message' => 'Миграции применены'];
    }

    /** @return array{handled: bool, status: string, message: string} */
    private function verify(SystemUpdate $update): array
    {
        $installed = (string) config('nabilet.version', '0.0.0');
        $target = (string) $update->to_version;
        $healthy = ! in_array($this->probe->status(), [HealthProbe::DOWN, HealthProbe::ERROR], true);
        $filesOk = is_file(base_path('vendor/autoload.php')) && is_file(base_path('public_html/index.php'));
        $migrationState = $this->journal->migrationState((int) $update->id);
        $dbCompatible = ! in_array($migrationState, ['started', 'partial', 'uncertain'], true)
            && ! $this->hasPendingMigrations();

        if (! hash_equals($target, $installed) || ! $healthy || ! $filesOk || ! $dbCompatible) {
            $this->fail($update, sprintf(
                'Проверка не прошла: версия %s, ожидалась %s, состояние зависимостей %s, миграции %s.',
                $installed,
                $target,
                $this->probe->status(),
                $migrationState . ($this->hasPendingMigrations() ? ' (есть неприменённые)' : ''),
            ), true);

            return ['handled' => true, 'status' => UpdateStatus::FAILED_AFTER_SWAP, 'message' => 'Миграции не применены'];
        }

        $this->applier->maintenanceOff();
        $this->journal->record((int) $update->id, 'complete', [
            'status' => 'done',
            'version' => $installed,
        ]);
        $this->transition($update, UpdateStatus::DONE, 'verified');
        $update->finished_at = now();
        $update->save();
        $this->writeState($update);

        Cache::forget((string) config('updates.check_cache_key', 'updates:feed:check'));
        $this->storage->prune((int) config('updates.keep_releases', 2));

        Log::channel('security')->info('Обновление системы завершено', [
            'update_id' => $update->id,
            'version' => $target,
        ]);

        return ['handled' => true, 'status' => UpdateStatus::DONE, 'message' => 'Обновление завершено'];
    }

    /** @return array{handled: bool, status: string, message: string} */
    private function rollBack(SystemUpdate $update): array
    {
        $this->applier->rollback((int) $update->id);
        $this->transition($update, UpdateStatus::ROLLED_BACK, 'restored');
        $update->finished_at = now();
        $update->save();
        $this->writeState($update);

        Cache::forget((string) config('updates.check_cache_key', 'updates:feed:check'));
        Log::channel('security')->warning('Обновление системы откачено', ['update_id' => $update->id]);

        return ['handled' => true, 'status' => UpdateStatus::ROLLED_BACK, 'message' => 'Откат завершён'];
    }

    /** Есть ли миграции, которые не зафиксированы в таблице migrations. */
    private function hasPendingMigrations(): bool
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('migrations')) {
                return true;
            }
            $ran = array_map('strval', DB::table('migrations')->pluck('migration')->all());
            foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
                if (! in_array(basename($path, '.php'), $ran, true)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            // Нет соединения с БД — совместимость подтвердить нельзя.
            return true;
        }
    }

    private function manifestFor(SystemUpdate $update): ReleaseManifest
    {
        $sha = (string) $update->artifact_sha256;

        return new ReleaseManifest(
            (string) $update->to_version,
            (string) ($update->created_at?->toIso8601String() ?? now()->toIso8601String()),
            (string) config('updates.channel', 'stable'),
            basename((string) $update->artifact),
            $sha,
            (int) (@filesize($this->storage->archive((int) $update->id, (string) $update->artifact)) ?: 0),
            '8.3.0',
            '9.0.0',
            [],
            [],
            0,
            '',
        );
    }

    private function fail(SystemUpdate $update, string $message, bool $afterSwap): void
    {
        $target = $afterSwap ? UpdateStatus::FAILED_AFTER_SWAP : UpdateStatus::FAILED;
        if (UpdateStatus::canTransition((string) $update->status, $target)) {
            $this->transition($update, $target, 'error');
        } else {
            $update->status = $target;
            $update->step = 'error';
        }

        $update->error = mb_substr($message, 0, 4000);
        $update->finished_at = now();
        $update->save();
        $this->writeState($update);

        Log::channel('security')->error('Обновление системы прервано', [
            'update_id' => $update->id,
            'status' => $target,
            'message' => $update->error,
        ]);
        Log::channel('alerts')->error('self-update: ' . $target, [
            'update_id' => $update->id,
            'message' => $update->error,
        ]);
    }

    private function transition(SystemUpdate $update, string $to, string $step): void
    {
        $from = (string) $update->status;
        if ($from !== $to && ! UpdateStatus::canTransition($from, $to)) {
            throw new AppError(
                sprintf('Недопустимый переход обновления: %s → %s.', $from, $to),
                'UPDATE_INVALID_TRANSITION',
                409,
            );
        }

        $update->status = $to;
        $update->step = $step;
        $update->save();
        $this->writeState($update);
    }

    private function writeState(SystemUpdate $update): void
    {
        $state = $update->toAdminArray() + [
            'updated_at' => now()->toIso8601String(),
            'locked' => $update->locked_at !== null,
        ];
        $this->storage->writeState((int) $update->id, $state);
    }
}

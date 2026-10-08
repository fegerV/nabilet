<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Nabilet\Core\Logging\RedactSensitiveData;
use Nabilet\Modules\System\Services\HealthProbe;

/**
 * Админская диагностика установки: состояние, просмотр логов, очистка кэша.
 *
 * ДОСТУП
 *   Все методы вызываются только из-под `auth:api` + `admin` — маршруты
 *   объявлены в `app/Modules/System/routes/api.php`. Это не формальность:
 *   ответ `status()` содержит точные версии PHP и Laravel (номер версии
 *   отображается на список известных уязвимостей), геометрию диска и путь к
 *   логам, а `viewLogs()` — содержимое лога. Анонимному клиенту это отдавать
 *   нельзя. Публичный `GET /api/v1/health` (см. `HealthController`) отвечает
 *   на вопрос «живо ли» минимальной нагрузкой и без этих сведений.
 *
 * ЧЕГО ЗДЕСЬ СОЗНАТЕЛЬНО НЕТ
 *   Раньше в классе были ещё три метода — `optimizeDatabase()`,
 *   `clearCache()` с `Cache::flush()` и `createBackup()`. Они не были
 *   подключены ни одним маршрутом (контроллер вообще был недостижим по HTTP),
 *   то есть правок поведения здесь нет — только удаление недостижимого кода.
 *   Основания, по которым они не были заведены как эндпоинты:
 *
 *   - `optimizeDatabase()` выполняет `OPTIMIZE TABLE` по КАЖДОЙ таблице. Это
 *     блокирующая DDL: на большой базе она идёт минутами, а HTTP-запрос
 *     столько не живёт — клиент получит таймаут, операция продолжится, и
 *     повторить её будет нечем. Место такой команды — `artisan`/cron.
 *   - `createBackup()` ссылался на `App\Modules\Backups\Services\
 *     YandexDiskBackupService`, который запускает `mysqldump` через `exec()`
 *     из веб-запроса, передаёт пароль в аргументах командной строки (виден в
 *     `ps`) и загружает архив на Яндекс.Диск. При этом в проекте уже есть
 *     проверенный конвейер `database/backup/backup.sh`: `--single-transaction`,
 *     сжатие, опциональный GPG, сверка SHA-256, загрузка в S3 и ротация.
 *     Заводить по HTTP вторую, худшую реализацию бэкапа — это понижение
 *     надёжности, а не диагностика.
 *   - `clearCache()` вызывал `Cache::flush()`, который очищает хранилище
 *     целиком, а не кэш приложения. Оставлена только очистка штатным
 *     `cache:clear`.
 *
 *   Если в админке появится страница «Система», эти операции стоит завести
 *   как artisan-команды и запускать их оттуда фоново, а не расширять этот
 *   контроллер.
 */
class SystemStatusController extends Controller
{
    /** Сколько дней считать резервную копию свежей. */
    private const BACKUP_STALE_HOURS = 26;

    public function __construct(private readonly HealthProbe $probe)
    {
    }

    /**
     * Подробный отчёт о состоянии для админки.
     *
     * Код ответа здесь всегда 200: это отчёт, а не проба. Отвечать 503 на
     * «состояние» неверно — тело всё равно содержит разбор, и клиент, который
     * умеет его читать, получил бы ошибку вместо данных. Машиночитаемый
     * вердикт о работоспособности отдаёт `GET /api/v1/health`, и решение о
     * критичности зависимостей принимает `HealthProbe` — то же самое, что и
     * для публичного эндпоинта. Два разных ответа на вопрос «здорово ли» были
     * бы ровно тем расхождением, которое здесь недопустимо.
     */
    public function status(): JsonResponse
    {
        $checks = $this->probe->checks();

        return response()->json([
            'status' => $this->probe->status(),
            'checks' => [
                'database' => $this->describe($checks['database'], 'Соединение с БД установлено'),
                'redis' => $this->describe($checks['redis'], 'Redis отвечает'),
                'queue' => $this->describe($checks['queue'], 'Очередь доступна'),
                'disk_space' => $this->checkDiskSpace(),
                'last_backup' => $this->getLastBackupTime(),
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'memory_usage_bytes' => memory_get_usage(true),
            ],
            'version' => (string) config('nabilet.version', '0.0.0'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Просмотр последних строк лога.
     *
     * Файл на диске уже прошёл фильтр `RedactSensitiveData` (каналы `daily` и
     * `security`), но здесь строки проходят его ЕЩЁ РАЗ и это не избыточно:
     * в файле лежат записи, сделанные до появления фильтра, и отдать их из
     * админки как есть — значит воспроизвести утечку через интерфейс.
     */
    public function viewLogs(Request $request, RedactSensitiveData $redactor): JsonResponse
    {
        $lines = max(1, min(2000, (int) $request->get('lines', 100)));

        // Канал `daily` пишет в `laravel-YYYY-MM-DD.log`; файла `laravel.log`
        // в проекте нет, поэтому прежний код всегда отвечал «Log file not
        // found» и логи нельзя было посмотреть вообще. Берём самый свежий по
        // mtime.
        $candidates = glob(storage_path('logs/laravel*.log')) ?: [];
        $logPath = null;
        $newest = -1;

        foreach ($candidates as $candidate) {
            $mtime = @filemtime($candidate);
            if ($mtime !== false && $mtime > $newest) {
                $newest = $mtime;
                $logPath = $candidate;
            }
        }

        if ($logPath === null) {
            return response()->json(['logs' => [], 'message' => 'Log file not found']);
        }

        $file = new \SplFileObject($logPath);
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key() + 1;

        $startLine = max(0, $totalLines - $lines);
        $logs = [];

        $file->seek($startLine);
        while (! $file->eof()) {
            $line = $file->current();
            if (! empty(trim($line))) {
                $logs[] = $redactor->scrubText($line);
            }
            $file->next();
        }

        return response()->json([
            'logs' => array_reverse($logs),
            'total_lines' => $totalLines,
            'showing_from' => $startLine,
            'file' => basename($logPath),
        ]);
    }

    /**
     * Очистка кэша приложения.
     *
     * Событие пишется в канал `security`: очистка кэша — привилегированное
     * действие, и вопрос «кто это сделал и когда» должен иметь ответ.
     */
    public function clearCache(): JsonResponse
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('view:clear');

            Log::channel('security')->warning('Кэш очищен через админку', [
                'user_id' => request()->user()?->getAuthIdentifier(),
                'ip' => request()->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Кэш успешно очищен',
            ]);
        } catch (\Throwable $e) {
            // Внутренний текст исключения остаётся в логе, клиенту — конверт.
            Log::error('Не удалось очистить кэш через админку', ['exception' => $e]);

            return $this->envelope('CACHE_CLEAR_FAILED', 'Не удалось очистить кэш.', 500);
        }
    }

    /**
     * Конверт §66. Внутренние сообщения исключений в ответ не попадают —
     * только в лог.
     */
    private function envelope(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => [],
            ],
        ], $status);
    }

    /**
     * @param  array{state: string, critical: bool}  $check
     * @return array<string, mixed>
     */
    private function describe(array $check, string $okMessage): array
    {
        return match ($check['state']) {
            HealthProbe::OK => ['status' => 'ok', 'message' => $okMessage],
            HealthProbe::NOT_CONFIGURED => [
                'status' => 'info',
                'message' => 'Не используется в текущей конфигурации',
            ],
            default => ['status' => 'error', 'message' => 'Проверка не пройдена'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDiskSpace(): array
    {
        $freeSpace = disk_free_space(base_path());
        $totalSpace = disk_total_space(base_path());

        // `disk_*_space()` возвращает false при ошибке; деление на 0 давало
        // DivisionByZeroError (не Exception) — то есть фатал мимо catch.
        if ($freeSpace === false || $totalSpace === false || $totalSpace <= 0) {
            return ['status' => 'error', 'message' => 'Disk space is not readable.'];
        }

        $usedPercent = (($totalSpace - $freeSpace) / $totalSpace) * 100;

        $status = $usedPercent > 90 ? 'error' : ($usedPercent > 80 ? 'warning' : 'ok');

        return [
            'status' => $status,
            'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
            'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
            'used_percent' => round($usedPercent, 2),
        ];
    }

    /**
     * Свежесть последней резервной копии.
     *
     * Раньше метод искал `*.zip` в `storage/app/backups` — каталоге, в который
     * штатный конвейер НИКОГДА не пишет (он кладёт `nabilet_*.sql[.gz|.zst]` в
     * `database/backup`, см. `database/backup/backup.sh`). То есть при живой
     * истории бэкапов отчёт всегда показывал «No backups found» — зеркальное
     * отражение той же болезни, что и вечно-зелёный health-check: сигнал,
     * который врёт. Проверяем там, где файлы действительно лежат, и считаем
     * давность: бэкап старше суток — это `warning`, а не «информация».
     */
    private function getLastBackupTime(): array
    {
        $backupDir = database_path('backup');

        if (! is_dir($backupDir)) {
            return ['status' => 'error', 'message' => 'Каталог резервных копий не найден'];
        }

        $files = array_merge(
            glob($backupDir . '/nabilet_*.sql') ?: [],
            glob($backupDir . '/nabilet_*.sql.gz') ?: [],
            glob($backupDir . '/nabilet_*.sql.zst') ?: [],
        );

        if ($files === []) {
            return ['status' => 'error', 'message' => 'Резервные копии не найдены'];
        }

        $newest = 0;
        $latest = null;

        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime > $newest) {
                $newest = $mtime;
                $latest = $file;
            }
        }

        if ($latest === null) {
            return ['status' => 'error', 'message' => 'Резервные копии не найдены'];
        }

        $ageHours = round((time() - $newest) / 3600, 1);

        return [
            'status' => $ageHours > self::BACKUP_STALE_HOURS ? 'warning' : 'ok',
            'last_backup' => date('Y-m-d H:i:s', $newest),
            'age_hours' => $ageHours,
            'file' => basename($latest),
        ];
    }
}

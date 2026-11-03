<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Services;

/**
 * Хостовые проверки установки: свободное место на диске и свежесть последней
 * резервной копии.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ КЛАСС
 *   Эти две проверки жили приватными методами `SystemStatusController` — и
 *   ровно поэтому не могли ответить ни на какой другой вопрос. Алерт-команда
 *   `system:selfcheck` задаёт те же вопросы по расписанию: если бы она собрала
 *   свою копию правил («что считать протухшим бэкапом», «какой диск полон»),
 *   мы получили бы тот класс дефекта, который в этом проекте уже ловили не
 *   раз — два места отвечают на один вопрос по-разному и расходятся молча.
 *   Решает здесь один владелец; контроллер оформляет ответ для админки,
 *   команда — сигнал для алерта.
 *
 *   Зависимости БД/Redis/очереди — не здесь: ими владеет `HealthProbe` (его же
 *   правило приоритетов `down` > `degraded` переиспользуется командой).
 */
final class HostChecks
{
    /** Сколько часов считать резервную копию свежей. */
    public const BACKUP_STALE_HOURS = 26;

    /**
     * `$diskFreeBytes`/`$diskTotalBytes` — шов для тестов: реальный диск
     * подменить нельзя, а feature-тест, зависящий от заполненности диска
     * того компьютера, где его прогоняют, падал бы сам по себе. В production
     * и контроллеру, и команде эти параметры не передаются.
     */
    public function __construct(
        private readonly ?string $backupDir = null,
        private readonly ?float $diskFreeBytes = null,
        private readonly ?float $diskTotalBytes = null,
    ) {
    }

    /**
     * Геометрия диска: заполняется ли хранилище.
     *
     * Пороги: >90 % — `error` (упирающийся в ноль диск роняет и логи, и
     * файловую очередь, и сессии), >80 % — `warning` (пора разбираться до
     * отказа). Между ними система считается здоровой: паника на 81 % стоит
     * дороже, чем польза.
     *
     * @return array{status: string, free_gb: float, total_gb: float, used_percent: float}|array{status: string, message: string}
     */
    public function diskSpace(): array
    {
        // Подставные числа имеют смысл только парой: один из двух параметров
        // без второго считать ошибкой конфигурации шва.
        if ($this->diskFreeBytes !== null && $this->diskTotalBytes !== null) {
            $freeSpace = $this->diskFreeBytes;
            $totalSpace = $this->diskTotalBytes;
        } else {
            $freeSpace = disk_free_space(base_path());
            $totalSpace = disk_total_space(base_path());
        }

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
     * Раньше (до переноса сюда) проверка искала `*.zip` в `storage/app/backups`
     * — каталоге, в который штатный конвейер НИКОГДА не пишет. Теперь смотрим
     * там, где файлы действительно лежат (`database/backup/backup.sh` кладёт
     * `nabilet_*.sql[.gz|.zst]`), и считаем давность: копия старше суток —
     * `warning` (дневной бэкап не случился или не выгрузился), отсутствие
     * каталога или копий — `error`.
     *
     * @return array{status: string, last_backup: string, age_hours: float, file: string}|array{status: string, message: string}
     */
    public function lastBackup(): array
    {
        $backupDir = $this->backupDir ?? database_path('backup');

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

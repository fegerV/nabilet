<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Nabilet\Core\Support\Paging;
use Nabilet\Modules\System\Services\HealthProbe;
use Nabilet\Modules\System\Services\LogReader;

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
 *   - `createBackup()` ссылался на `Nabilet\Modules\Backups\Services\
 *     YandexDiskBackupService`, который запускает `mysqldump` через `exec()`
 *     из веб-запроса и загружает архив на Яндекс.Диск. (Пароль в аргументах
 *     командной строки он больше НЕ передаёт — это исправлено: креденшелы
 *     уходят через `--defaults-extra-file` с правами 0600. Основание не
 *     заводить эндпоинт всё равно в силе: дамп из веб-запроса живёт дольше
 *     любого HTTP-таймаута.) При этом в проекте уже есть
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
     * Записи лога для админской страницы «Логи».
     *
     * Раньше метод отдавал «последние N строк» плоским списком: ни уровня, ни
     * даты, ни поиска — по такому ответу нельзя было ни отфильтровать ошибки,
     * ни найти запись по номеру заказа, а многострочный стектрейс выглядел как
     * десяток независимых строк. Теперь ответ — список ЗАПИСЕЙ (дата, уровень,
     * сообщение, контекст, стектрейс) с фильтрами и постраничностью.
     *
     * Параметры:
     *   - `file`   — имя файла из `files` (по умолчанию самый свежий);
     *   - `level`  — уровень или список через запятую (`ERROR`, `ERROR,WARNING`);
     *   - `search` — подстрока без учёта регистра по сообщению/контексту/трейсу;
     *   - `lines`  — размер страницы (1..500, по умолчанию 100);
     *   - `offset` — сколько подходящих записей пропустить.
     *
     * Разбор, обрезка хвоста и повторная чистка секретов живут в `LogReader`:
     * контроллер отвечает только за форму ответа. Повторная чистка не
     * избыточна — в файле лежат записи, сделанные до появления фильтра, и
     * отдать их из админки как есть значило бы воспроизвести утечку через
     * интерфейс.
     */
    public function viewLogs(Request $request, LogReader $reader): JsonResponse
    {
        $lines = Paging::limit((int) $request->get('lines', 100), 100, 500);
        $offset = max(0, (int) $request->get('offset', 0));

        $levels = array_values(array_filter(array_map(
            static fn (string $level): string => strtoupper(trim($level)),
            explode(',', (string) $request->get('level', ''))
        ), static fn (string $level): bool => in_array($level, LogReader::LEVELS, true)));

        $search = $request->get('search');
        $search = is_string($search) ? $search : null;

        $file = $request->get('file');
        $result = $reader->read(is_string($file) ? $file : null, $levels, $search, $lines, $offset);

        if ($result === null) {
            // Файл не найден или имя недопустимо — не 404: страница должна
            // остаться рабочей и показать список того, что есть.
            return response()->json([
                'file' => null,
                'entries' => [],
                'matched' => 0,
                'total_entries' => 0,
                'limit' => $lines,
                'offset' => $offset,
                'truncated' => false,
                'levels_present' => [],
                'levels' => LogReader::LEVELS,
                'files' => $reader->files(),
                'message' => 'Файл лога не найден.',
            ]);
        }

        return response()->json($result + [
            'levels' => LogReader::LEVELS,
            'files' => $reader->files(),
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

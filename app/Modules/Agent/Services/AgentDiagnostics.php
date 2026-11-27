<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nabilet\Modules\System\Services\HealthProbe;
use Nabilet\Modules\System\Services\HostChecks;
use Nabilet\Modules\System\Services\LogReader;
use Nabilet\Modules\Updater\Services\UpdateStorage;

/**
 * Обезличенные диагностические срезы для MCP-агента.
 *
 * Одно место на все диагностические инструменты: агент видит те же правила,
 * что админка и `system:selfcheck`, но НЕ видит секретов, токенов, конфиден-
 * циальных полей БД и персональных данных. Здесь сознательно нет:
 *   - значений .env (только факты «настроено/не настроено»);
 *   - payload и exception failed jobs (там бывают e-mail и номера заказов);
 *   - полей webhooks.secret_encrypted, api_keys, personal_access_tokens;
 *   - произвольного SQL или записи файлов.
 *
 * Все методы read-only и не пишут в БД, кеш или логи (кроме чтения логов).
 */
final class AgentDiagnostics
{
    public function __construct(
        private readonly HealthProbe $probe,
        private readonly HostChecks $host,
        private readonly LogReader $logs,
        private readonly UpdateStorage $updateStorage,
    ) {
    }

    /** @return array<string, mixed> */
    public function applicationVersion(): array
    {
        return [
            'version' => (string) config('nabilet.version', '0.0.0'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'environment' => (string) config('app.env', 'unknown'),
            'debug' => (bool) config('app.debug', false),
            'installed' => is_file(storage_path('install.lock')),
        ];
    }

    /** @return array<string, mixed> */
    public function systemStatus(): array
    {
        $checks = $this->probe->checks();

        return [
            'status' => $this->probe->status(),
            'checks' => [
                'database' => $checks['database']['state'],
                'redis' => $checks['redis']['state'],
                'queue' => $checks['queue']['state'],
                'database_invariants' => $this->probe->databaseInvariants(),
            ],
            'disk' => $this->host->diskSpace(),
            'last_backup' => $this->host->lastBackup(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function systemRequirements(): array
    {
        $extensions = ['json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'curl', 'zip'];
        $available = [];
        foreach ($extensions as $extension) {
            $available[$extension] = extension_loaded($extension);
        }

        return [
            'php' => [
                'version' => PHP_VERSION,
                'minimum' => '8.3.0',
                'satisfied' => version_compare(PHP_VERSION, '8.3.0', '>='),
            ],
            'extensions' => $available,
            'writable' => [
                'storage' => is_writable(storage_path()),
                'bootstrap_cache' => is_writable(base_path('bootstrap/cache')),
            ],
            'limits' => [
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            ],
            'https' => str_starts_with((string) config('app.url', ''), 'https://'),
        ];
    }

    /** @return array<string, mixed> */
    public function cronStatus(): array
    {
        $fresh = $this->updateStorage->heartbeatFresh(180);

        return [
            'heartbeat_fresh' => $fresh,
            'heartbeat_age_seconds' => $this->heartbeatAge(),
            'schedule_commands' => [
                'schedule:run' => 'каждую минуту',
                'nabilet:update:tick' => 'каждую минуту (через schedule:run)',
                'backup:run' => 'ежедневно',
            ],
            'suggested_cron' => sprintf(
                '* * * * * cd %s && %s artisan schedule:run >> /dev/null 2>&1',
                escapeshellarg(base_path()),
                escapeshellarg(PHP_BINARY),
            ),
            'hint' => $fresh
                ? 'Планировщик выполнялся за последние 3 минуты.'
                : 'Планировщик не запускался: проверьте cron и команду выше.',
        ];
    }

    /** @return array<string, mixed> */
    public function queueStatus(): array
    {
        return [
            'connection' => (string) config('queue.default', 'sync'),
            'pending_jobs' => $this->countTable('jobs'),
            'failed_jobs' => $this->countTable('failed_jobs'),
            'note' => 'Тела заданий и параметры выполнений не выдаются: они могут содержать персональные данные.',
        ];
    }

    /** @return array<string, mixed> */
    public function recentErrors(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $result = $this->logs->read(null, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], null, $limit);
        $entries = [];

        foreach (($result['entries'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $entries[] = [
                'date' => (string) ($entry['date'] ?? ''),
                'level' => (string) ($entry['level'] ?? ''),
                // Сообщение уже прошло RedactSensitiveData в LogReader; обрезка
                // дополнительно не даёт агенту случайно получить длинный дамп.
                'message' => mb_substr((string) ($entry['message'] ?? ''), 0, 400),
            ];
        }

        return [
            'entries' => $entries,
            'matched' => (int) ($result['matched'] ?? count($entries)),
            'truncated' => (bool) ($result['truncated'] ?? false),
            'note' => 'Стектейсы и контекст не выдаются: они могут содержать персональные данные и секреты.',
        ];
    }

    /** @return array<string, mixed> */
    public function failedJobs(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        try {
            $rows = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit($limit)
                ->get(['id', 'queue', 'failed_at']);
        } catch (\Throwable) {
            return ['entries' => [], 'note' => 'Таблица failed_jobs недоступна.'];
        }

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'id' => (int) $row->id,
                'queue' => (string) $row->queue,
                'failed_at' => (string) $row->failed_at,
            ];
        }

        return [
            'entries' => $entries,
            'total' => $this->countTable('failed_jobs'),
            'note' => 'Текст исключения и payload не выдаются: там могут быть e-mail, заказы и токены.',
        ];
    }

    /** @return array<string, mixed> */
    public function databaseStatus(): array
    {
        try {
            $connection = DB::connection();
            $version = (string) ($connection->selectOne('select version() as v')?->v ?? '');
            $applied = $this->countTable('migrations');
            $files = count(glob(base_path('database/migrations/*.php')) ?: []);
        } catch (\Throwable $e) {
            return ['reachable' => false, 'error_message' => 'База данных недоступна: ' . get_class($e)];
        }

        return [
            'reachable' => true,
            'driver' => (string) config('database.default', 'mysql'),
            'server_version' => $version,
            'migrations_applied' => $applied,
            'migration_files' => $files,
            'pending_migrations' => max(0, $files - $applied),
            'invariants' => $this->probe->databaseInvariants(),
        ];
    }

    /** @return array<string, mixed> */
    public function moduleStatus(): array
    {
        $modules = config('nabilet.modules');
        $modules = is_array($modules) ? $modules : [];
        $registered = [];
        foreach ($modules as $name => $provider) {
            $registered[] = [
                'name' => (string) $name,
                'provider' => (string) $provider,
                'loadable' => class_exists((string) $provider),
            ];
        }

        return [
            'registered' => count($registered),
            'loadable' => count(array_filter($registered, static fn (array $m): bool => $m['loadable'])),
            'modules' => $registered,
        ];
    }

    /** @return array<string, mixed> */
    public function integrationStatus(): array
    {
        $shop = (string) config('nabilet.payments.yookassa.shop_id', '');
        $secret = (string) config('nabilet.payments.yookassa.secret_key', '');
        $smtpHost = (string) config('mail.mailers.smtp.host', config('mail.host', ''));
        $feed = (string) config('updates.feed_url', '');

        return [
            'payments' => [
                'providers' => array_values((array) config('nabilet.payments.providers', [])),
                'yookassa_configured' => $shop !== '' && $secret !== '',
            ],
            'smtp' => ['configured' => $smtpHost !== ''],
            'updates' => [
                'feed_configured' => $feed !== '',
                'public_key_configured' => trim((string) config('updates.public_key', '')) !== '',
            ],
            'note' => 'Показываются только факты наличия конфигурации; ключи и пароли не выдаются.',
        ];
    }

    /** @return array<string, mixed> */
    public function backupStatus(): array
    {
        return $this->host->lastBackup();
    }

    /** @return array<string, mixed> */
    public function updateStatus(): array
    {
        try {
            $row = DB::table('system_updates')
                ->orderByDesc('id')
                ->limit(1)
                ->first([
                    'from_version', 'to_version', 'source', 'status', 'step',
                    'backup_status', 'started_at', 'finished_at', 'created_at',
                ]);
        } catch (\Throwable) {
            return ['available' => false, 'note' => 'Таблица обновлений недоступна.'];
        }

        return [
            'available' => true,
            'latest' => $row === null ? null : (array) $row,
            'cron_heartbeat_fresh' => $this->updateStorage->heartbeatFresh(180),
        ];
    }

    /** @return array<string, mixed> */
    public function apiCapabilities(): array
    {
        return [
            'transport' => 'Streamable HTTP',
            'endpoint' => '/mcp',
            'model' => 'two-phase (prepare → human approval → confirm)',
            'tool_groups' => [
                'diagnostics' => 'read-only, агент видит состояние без секретов',
                'content' => 'подготовка изменений; применяет человек',
                'testing' => 'только read-only проверки',
            ],
            'capabilities' => array_keys(\Nabilet\Modules\Agent\Support\AgentCapabilities::labels()),
            'denied' => [
                'произвольный SQL',
                'исполнение PHP и shell',
                'чтение .env',
                'произвольная запись файлов',
                'платежные ключи, пароли, QR-секреты',
                'полный экспорт персональных данных',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function installationReport(): array
    {
        return [
            'application' => $this->applicationVersion(),
            'requirements' => $this->systemRequirements(),
            'status' => $this->systemStatus(),
            'database' => $this->databaseStatus(),
            'cron' => $this->cronStatus(),
            'queue' => $this->queueStatus(),
            'modules' => ['registered' => $this->moduleStatus()['registered'], 'loadable' => $this->moduleStatus()['loadable']],
            'integrations' => $this->integrationStatus(),
            'backups' => $this->backupStatus(),
            'updates' => $this->updateStatus(),
            'generated_at' => now()->toIso8601String(),
            'note' => 'Отчёт обезличен: пароли, токены и персональные данные исключены.',
        ];
    }

    /** @return array<string, mixed> */
    public function readonlySelfcheck(): array
    {
        $status = $this->systemStatus();
        $database = $this->databaseStatus();
        $cron = $this->cronStatus();
        $problems = [];

        if (($status['status'] ?? '') !== HealthProbe::OK) {
            $problems[] = 'Состояние систем: ' . (string) $status['status'];
        }
        if (($database['pending_migrations'] ?? 0) > 0) {
            $problems[] = 'Есть неприменённые миграции: выполните php artisan migrate --force.';
        }
        if (! ($cron['heartbeat_fresh'] ?? false)) {
            $problems[] = 'Планировщик не запускался: проверьте cron.';
        }
        if ((($status['last_backup'] ?? [])['status'] ?? '') === 'error') {
            $problems[] = 'Свежей резервной копии нет: проверьте backup:run.';
        }

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'status' => $status['status'] ?? 'unknown',
            'checked_at' => now()->toIso8601String(),
            'note' => 'Проверка только читает состояние: алерты и уведомления не отправляются.',
        ];
    }

    /** @return array<string, mixed> */
    public function routeStatus(): array
    {
        $routes = Route::getRoutes();
        $critical = [
            'GET /api/v1/health',
            'POST /mcp',
            'GET /',
            'GET /install',
        ];
        $present = [];
        foreach ($critical as $candidate) {
            [$method, $uri] = explode(' ', $candidate, 2);
            try {
                $routes->match(\Illuminate\Http\Request::create('/' . ltrim($uri, '/'), $method));
                $found = true;
            } catch (\Throwable) {
                // NotFoundHttpException / MethodNotAllowedHttpException: маршрута нет.
                $found = false;
            }
            // Список записей вместо карты с ключами "GET /api/v1/health":
            // клиентские path-выражения не умеют точки и слеши в ключах.
            $present[] = ['method' => $method, 'uri' => ltrim($uri, '/'), 'present' => $found];
        }

        return [
            'routes_total' => count($routes->getRoutes()),
            'critical' => $present,
            'note' => 'Проверяется наличие маршрутов в реестре, а не их HTTP-вызов.',
        ];
    }

    private function countTable(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function heartbeatAge(): ?int
    {
        $path = $this->updateStorage->heartbeatFile();
        if (! is_file($path)) {
            return null;
        }
        $mtime = @filemtime($path);

        return $mtime === false ? null : time() - $mtime;
    }
}

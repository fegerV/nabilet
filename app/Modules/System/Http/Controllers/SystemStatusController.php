<?php

namespace Nabilet\Modules\System\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Диагностика и обслуживание установки: статус, очистка кэша, OPTIMIZE TABLE,
 * просмотр логов, бэкап по требованию.
 *
 * ВНИМАНИЕ: модуль не зарегистрирован ни в `routes/api.php`, ни в `routes/web.php`,
 * и в меню админки пункта «Система» нет — контроллер недостижим по HTTP. Плюс
 * раньше он не мог быть загружен вообще: объявлял пространство имён `App\Modules\...`
 * и наследовал `App\Http\Controllers\Controller`, которого в проекте не существует
 * (`app/Http/Controllers/` отсутствует), а фасад `Storage` использовался без импорта.
 * Пространство имён и импорты приведены к принятому в проекте `Nabilet\Modules\...`,
 * чтобы файл не оставался миной: любой будущий маршрут на него падал бы фаталом.
 *
 * Решение «подключать или удалять» — за владельцем продукта, оно не принимается
 * молча правкой namespace.
 */
class SystemStatusController extends Controller
{
    /**
     * Проверка состояния системы
     */
    public function status()
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'disk_space' => $this->checkDiskSpace(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory_usage' => memory_get_usage(true),
            'last_backup' => $this->getLastBackupTime(),
        ];

        $overallStatus = 'healthy';
        foreach ($checks as $key => $value) {
            if (is_array($value) && isset($value['status']) && $value['status'] === 'error') {
                $overallStatus = 'degraded';
                break;
            }
        }

        return response()->json([
            'status' => $overallStatus,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Очистка кэша
     */
    public function clearCache()
    {
        try {
            Cache::flush();
            \Artisan::call('cache:clear');
            \Artisan::call('config:clear');
            \Artisan::call('view:clear');
            
            return response()->json([
                'success' => true,
                'message' => 'Кэш успешно очищен',
            ]);
        } catch (\Exception $e) {
            return $this->envelope('CACHE_CLEAR_FAILED', 'Не удалось очистить кэш.', 500);
        }
    }

    /**
     * Оптимизация базы данных
     */
    public function optimizeDatabase()
    {
        try {
            $tables = DB::select('SHOW TABLES');
            $dbName = config('database.connections.mysql.database');
            $optimizedTables = [];

            foreach ($tables as $tableObj) {
                $tableName = reset($tableObj);
                if ($tableName !== 'migrations') {
                    DB::statement("OPTIMIZE TABLE {$tableName}");
                    $optimizedTables[] = $tableName;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'База данных оптимизирована',
                'tables_optimized' => count($optimizedTables),
            ]);
        } catch (\Exception $e) {
            return $this->envelope('DB_OPTIMIZE_FAILED', 'Не удалось оптимизировать базу данных.', 500);
        }
    }

    /**
     * Просмотр логов
     */
    public function viewLogs(Request $request)
    {
        $lines = max(1, min(2000, (int) $request->get('lines', 100)));
        // Канал `daily` пишет в `laravel-YYYY-MM-DD.log`; файла `laravel.log`
        // в проекте нет, поэтому прежний код всегда отвечал «Log file not found»
        // и логи нельзя было посмотреть вообще. Берём самый свежий по mtime.
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
        while (!$file->eof()) {
            $line = $file->current();
            if (!empty(trim($line))) {
                $logs[] = $line;
            }
            $file->next();
        }

        return response()->json([
            'logs' => array_reverse($logs),
            'total_lines' => $totalLines,
            'showing_from' => $startLine,
        ]);
    }

    /**
     * Создание бэкапа по требованию
     */
    public function createBackup()
    {
        try {
            $backupService = new \App\Modules\Backups\Services\YandexDiskBackupService();
            $result = $backupService->createFullBackup();

            return response()->json([
                'success' => true,
                'message' => 'Бэкап успешно создан и загружен на Яндекс.Диск',
                'backup' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->envelope('BACKUP_FAILED', 'Не удалось создать резервную копию.', 500);
        }
    }

    /**
     * Конверт §66. Внутренние сообщения исключений в ответ не попадают — только
     * в лог: раньше `'message' => '…: ' . $e->getMessage()` публиковал клиенту
     * текст драйвера БД и абсолютные пути.
     */
    private function envelope(string $code, string $message, int $status): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => [],
            ],
        ], $status);
    }

    private function checkDatabase()
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'ok', 'message' => 'Database connection successful'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Database connection failed.'];
        }
    }

    private function checkCache()
    {
        try {
            Cache::put('health_check', 'ok', 60);
            $value = Cache::get('health_check');
            return $value === 'ok' 
                ? ['status' => 'ok', 'message' => 'Cache working properly']
                : ['status' => 'error', 'message' => 'Cache read/write failed'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Cache is not available.'];
        }
    }

    private function checkStorage()
    {
        try {
            $testFile = 'health_check_' . time() . '.tmp';
            Storage::put($testFile, 'test');
            $exists = Storage::exists($testFile);
            Storage::delete($testFile);

            return $exists
                ? ['status' => 'ok', 'message' => 'Storage working properly']
                : ['status' => 'error', 'message' => 'Storage write/read failed'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Storage is not writable.'];
        }
    }

    private function checkDiskSpace()
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

    private function getLastBackupTime()
    {
        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            return ['status' => 'info', 'message' => 'No backups found'];
        }

        $files = glob($backupDir . '/*.zip');
        if (empty($files)) {
            return ['status' => 'info', 'message' => 'No backups found'];
        }

        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $latestBackup = $files[0];
        $time = filemtime($latestBackup);

        return [
            'status' => 'ok',
            'last_backup' => date('Y-m-d H:i:s', $time),
            'age_hours' => round((time() - $time) / 3600, 1),
        ];
    }
}

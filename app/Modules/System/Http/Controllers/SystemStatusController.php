<?php

namespace App\Modules\System\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при очистке кэша: ' . $e->getMessage(),
            ], 500);
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
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при оптимизации БД: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Просмотр логов
     */
    public function viewLogs(Request $request)
    {
        $lines = $request->get('lines', 100);
        $logPath = storage_path('logs/laravel.log');

        if (!file_exists($logPath)) {
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
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании бэкапа: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function checkDatabase()
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'ok', 'message' => 'Database connection successful'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
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
            return ['status' => 'error', 'message' => $e->getMessage()];
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
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function checkDiskSpace()
    {
        $freeSpace = disk_free_space(base_path());
        $totalSpace = disk_total_space(base_path());
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

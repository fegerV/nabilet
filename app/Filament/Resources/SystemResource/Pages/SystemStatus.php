<?php

namespace App\Filament\Resources\SystemResource\Pages;

use App\Filament\Resources\SystemResource;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SystemStatus extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    
    protected string $view = 'filament.pages.system-status';
    
    protected static ?string $title = 'Состояние системы';

    public array $systemChecks = [];
    
    public bool $isLoading = true;

    public function mount()
    {
        $this->runSystemChecks();
        $this->isLoading = false;
    }

    public function runSystemChecks()
    {
        // Database check
        try {
            DB::connection()->getPdo();
            $dbStatus = ['status' => 'ok', 'message' => 'Подключение успешно'];
        } catch (\Exception $e) {
            $dbStatus = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Cache check
        try {
            Cache::put('health_check', 'ok', 60);
            $cacheStatus = Cache::get('health_check') === 'ok'
                ? ['status' => 'ok', 'message' => 'Кэш работает']
                : ['status' => 'error', 'message' => 'Ошибка кэша'];
        } catch (\Exception $e) {
            $cacheStatus = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Storage check
        try {
            $testFile = 'health_' . time() . '.tmp';
            Storage::put($testFile, 'test');
            $exists = Storage::exists($testFile);
            Storage::delete($testFile);
            $storageStatus = $exists
                ? ['status' => 'ok', 'message' => 'Хранилище работает']
                : ['status' => 'error', 'message' => 'Ошибка записи'];
        } catch (\Exception $e) {
            $storageStatus = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Disk space
        $freeSpace = disk_free_space(base_path());
        $totalSpace = disk_total_space(base_path());
        $usedPercent = (($totalSpace - $freeSpace) / $totalSpace) * 100;
        $diskStatus = [
            'status' => $usedPercent > 90 ? 'error' : ($usedPercent > 80 ? 'warning' : 'ok'),
            'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
            'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
            'used_percent' => round($usedPercent, 2),
        ];

        $this->systemChecks = [
            'database' => $dbStatus,
            'cache' => $cacheStatus,
            'storage' => $storageStatus,
            'disk' => $diskStatus,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        ];
    }

    public function clearCache()
    {
        try {
            Cache::flush();
            \Artisan::call('cache:clear');
            \Artisan::call('config:clear');
            \Artisan::call('view:clear');

            Notification::make()
                ->title('Кэш очищен')
                ->success()
                ->send();

            $this->runSystemChecks();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Ошибка при очистке кэша')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function optimizeDatabase()
    {
        try {
            $tables = DB::select('SHOW TABLES');
            $count = 0;
            
            foreach ($tables as $tableObj) {
                $tableName = reset($tableObj);
                if ($tableName !== 'migrations') {
                    DB::statement("OPTIMIZE TABLE {$tableName}");
                    $count++;
                }
            }

            Notification::make()
                ->title('База данных оптимизирована')
                ->body("Оптимизировано таблиц: {$count}")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Ошибка оптимизации БД')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function getResource(): string
    {
        return SystemResource::class;
    }
}

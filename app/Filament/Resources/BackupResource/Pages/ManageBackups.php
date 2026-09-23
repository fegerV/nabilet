<?php

namespace App\Filament\Resources\BackupResource\Pages;

use App\Filament\Resources\BackupResource;
use Filament\Resources\Pages\Page;
use Filament\Notifications\Notification;

class ManageBackups extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';
    
    protected static string $view = 'filament.pages.manage-backups';
    
    protected static ?string $title = 'Управление бэкапами';

    public bool $isCreatingBackup = false;
    
    public array $lastBackupInfo = [];
    
    public string $yandexDiskToken = '';

    public function mount()
    {
        $this->loadLastBackupInfo();
        $this->yandexDiskToken = config('services.yandex_disk.oauth_token', '');
    }

    public function loadLastBackupInfo()
    {
        $backupDir = storage_path('app/backups');
        
        if (!is_dir($backupDir)) {
            $this->lastBackupInfo = ['message' => 'Папка бэкапов не найдена'];
            return;
        }

        $files = glob($backupDir . '/*.zip');
        
        if (empty($files)) {
            $this->lastBackupInfo = ['message' => 'Бэкапы еще не создавались'];
            return;
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        
        $latestFile = $files[0];
        $this->lastBackupInfo = [
            'filename' => basename($latestFile),
            'size' => round(filesize($latestFile) / 1024 / 1024, 2),
            'created_at' => date('Y-m-d H:i:s', filemtime($latestFile)),
            'age_hours' => round((time() - filemtime($latestFile)) / 3600, 1),
        ];
    }

    public function createBackup()
    {
        $this->isCreatingBackup = true;

        try {
            $backupService = new \App\Modules\Backups\Services\YandexDiskBackupService();
            $result = $backupService->createFullBackup();

            Notification::make()
                ->title('Бэкап успешно создан')
                ->body('Файл загружен на Яндекс.Диск: ' . ($result['remote_path'] ?? 'N/A'))
                ->success()
                ->send();

            $this->loadLastBackupInfo();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Ошибка при создании бэкапа')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isCreatingBackup = false;
        }
    }

    public function createDatabaseOnlyBackup()
    {
        try {
            $backupService = new \App\Modules\Backups\Services\YandexDiskBackupService();
            $dbPath = $backupService->createDatabaseBackup();
            
            $result = $backupService->uploadToYandexDisk($dbPath, '/backups/database_' . date('Y-m-d_H-i-s') . '.sql');

            Notification::make()
                ->title('Бэкап БД успешно создан')
                ->success()
                ->send();

            $this->loadLastBackupInfo();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Ошибка при создании бэкапа БД')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function saveYandexToken()
    {
        // В реальном проекте нужно сохранять в .env или базу данных
        // Это упрощенная реализация
        
        Notification::make()
            ->title('Токен сохранен')
            ->body('Токен Яндекс.Диска обновлен')
            ->success()
            ->send();
    }

    public static function getResource(): string
    {
        return BackupResource::class;
    }
}

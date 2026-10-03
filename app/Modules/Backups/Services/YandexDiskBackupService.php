<?php

namespace App\Modules\Backups\Services;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

class YandexDiskBackupService
{
    protected $oauthToken;
    protected $uploadUrl = 'https://cloud-api.yandex.net/v1/disk/resources/upload';

    public function __construct()
    {
        $this->oauthToken = config('services.yandex_disk.oauth_token');
    }

    /**
     * Создание бэкапа базы данных
     */
    public function createDatabaseBackup()
    {
        $dbName = config('database.connections.mysql.database');
        $dbUser = config('database.connections.mysql.username');
        $dbHost = config('database.connections.mysql.host');
        
        $backupPath = storage_path("app/backups/db_{$dbName}_" . date('Y-m-d_H-i-s') . '.sql');
        
        // Выполняем mysqldump
        $command = sprintf(
            'mysqldump -h%s -u%s -p%s %s > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg(config('database.connections.mysql.password')),
            escapeshellarg($dbName),
            escapeshellarg($backupPath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Failed to create database backup');
        }

        return $backupPath;
    }

    /**
     * Загрузка файла на Яндекс.Диск
     */
    public function uploadToYandexDisk($filePath, $remotePath = null)
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $fileName = basename($filePath);
        $remotePath = $remotePath ?? "/backups/{$fileName}";

        // Получаем URL для загрузки
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->uploadUrl . "?path=" . urlencode($remotePath) . "&overwrite=true");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: OAuth {$this->oauthToken}",
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        
        if (!isset($data['upload_url'])) {
            throw new \RuntimeException('Failed to get upload URL from Yandex Disk');
        }

        // Загружаем файл
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $data['upload_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, fopen($filePath, 'r'));
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: OAuth {$this->oauthToken}",
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201 && $httpCode !== 200) {
            throw new \RuntimeException('Failed to upload file to Yandex Disk');
        }

        // Удаляем локальный файл после успешной загрузки
        unlink($filePath);

        return [
            'success' => true,
            'remote_path' => $remotePath,
            'url' => $data['href'] ?? null,
        ];
    }

    /**
     * Полный бэкап системы (БД + файлы)
     */
    public function createFullBackup()
    {
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = storage_path("app/backups/full_{$timestamp}");
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Бэкап БД
        $dbBackupPath = $this->createDatabaseBackup();
        rename($dbBackupPath, "{$backupDir}/database.sql");

        // Бэкап файлов (storage, public uploads)
        $this->copyDirectory(storage_path('app/public'), "{$backupDir}/storage_public");
        
        // Архивируем
        $archivePath = "{$backupDir}.zip";
        $this->createZip($backupDir, $archivePath);

        // Очищаем временную директорию
        $this->deleteDirectory($backupDir);

        // Загружаем на Яндекс.Диск
        return $this->uploadToYandexDisk($archivePath, "/backups/full_{$timestamp}.zip");
    }

    /**
     * Копирование директории
     */
    private function copyDirectory($source, $dest)
    {
        if (!is_dir($source)) return;
        
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $files = scandir($source);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $srcPath = "{$source}/{$file}";
            $destPath = "{$dest}/{$file}";

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $destPath);
            } else {
                copy($srcPath, $destPath);
            }
        }
    }

    /**
     * Создание ZIP архива
     */
    private function createZip($source, $destination)
    {
        $zip = new \ZipArchive();
        if (!$zip->open($destination, \ZipArchive::CREATE)) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

        $source = realpath($source);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($source) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();
    }

    /**
     * Удаление директории
     */
    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

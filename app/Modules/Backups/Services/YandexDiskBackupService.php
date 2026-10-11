<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Services;

/**
 * Резервное копирование на Яндекс.Диск (ТЗ §72).
 *
 * ПРОСТРАНСТВО ИМЁН. Здесь стояло `App\Modules\Backups\Services` — единственный
 * такой файл среди модулей. Composer его находил (`"App\\": "app/"`), поэтому
 * ошибка не проявлялась в рантайме, но `tests/autoload.php` — тот самый
 * загрузчик, которым `php tests/run.php` (быстрый гейт CI, без Composer) тянет
 * код, — знает только `Nabilet\Core\`, `Nabilet\Modules\` и `Nabilet\Tests\`.
 * Класс был недостижим для этого раннера, то есть свойство безопасности,
 * которое здесь проверяется (пароль БД не попадает в командную строку), нельзя
 * было закрепить тестом в гейте. Соседний провайдер модуля
 * (`Nabilet\Modules\Backups\Providers`) назывался правильно с самого начала.
 */
class YandexDiskBackupService
{
    protected $oauthToken;

    protected $uploadUrl = 'https://cloud-api.yandex.net/v1/disk/resources/upload';

    public function __construct()
    {
        $this->oauthToken = config('services.yandex_disk.oauth_token');
    }

    /**
     * Команда дампа БД.
     *
     * ПАРОЛЬ НЕ ПЕРЕДАЁТСЯ АРГУМЕНТОМ КОМАНДНОЙ СТРОКИ (аудит
     * FINAL-ACCEPTANCE, B-2). `mysqldump -p<secret>` виден любому пользователю
     * хоста через `ps auxf` в момент выполнения и оседает в истории shell и
     * логах оркестраторов. Креденшелы уходят через `--defaults-extra-file`.
     *
     * `--single-transaction` — согласованный снимок InnoDB без блокировки
     * таблиц (иначе дамп остановил бы продажи); `--routines --triggers` — потому
     * что схема НАМЕРЕННО опирается на триггеры неизменяемости версий схемы
     * залов: без них восстановление дало бы другую БД.
     *
     * Метод статический и чистый (никаких `config()`/`storage_path()`), чтобы
     * его можно было проверить без файловой системы и без фреймворка — в том
     * числе в dependency-free наборе тестов.
     */
    public static function buildDumpCommand(string $credentialsFile, string $database, string $backupPath): string
    {
        return sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers %s > %s',
            escapeshellarg($credentialsFile),
            escapeshellarg($database),
            escapeshellarg($backupPath)
        );
    }

    /**
     * Содержимое временного файла креденшелов для `mysqldump`.
     *
     * Пароль ЗДЕСЬ ЕСТЬ — и это правильно: файл читает только `mysqldump`, он
     * создаётся с правами 0600 и удаляется сразу после дампа. Опасен не файл, а
     * аргумент командной строки, потому что его видят все.
     *
     * Значения квотируются: пароль со `"`, `\` или переводом строки иначе
     * испортил бы файл (INI-формат), и дамп молча пошёл бы без пароля.
     */
    public static function credentialsFileContents(string $host, int $port, string $user, string $password): string
    {
        $quote = static fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';

        return "[client]\n"
            . 'host=' . $quote($host) . "\n"
            . 'port=' . $port . "\n"
            . 'user=' . $quote($user) . "\n"
            . 'password=' . $quote($password) . "\n";
    }

    /**
     * Создание бэкапа базы данных.
     */
    public function createDatabaseBackup()
    {
        $connection = (array) config('database.connections.mysql');

        $dbName = (string) ($connection['database'] ?? '');
        $dbUser = (string) ($connection['username'] ?? '');
        $dbHost = (string) ($connection['host'] ?? '127.0.0.1');
        $dbPort = (int) ($connection['port'] ?? 3306);
        $dbPassword = (string) ($connection['password'] ?? '');

        if ($dbName === '') {
            throw new \RuntimeException('Database name is not configured; cannot create a backup.');
        }

        $backupDir = storage_path('app/backups');

        if (! is_dir($backupDir) && ! mkdir($backupDir, 0755, true) && ! is_dir($backupDir)) {
            throw new \RuntimeException("Cannot create the backup directory: {$backupDir}");
        }

        $backupPath = $backupDir . "/db_{$dbName}_" . date('Y-m-d_H-i-s') . '.sql';

        $credentialsFile = $this->writeCredentialsFile($dbHost, $dbPort, $dbUser, $dbPassword);

        try {
            exec(self::buildDumpCommand($credentialsFile, $dbName, $backupPath), $output, $exitCode);
        } finally {
            // Файл с паролем живёт ровно столько, сколько идёт дамп.
            if (is_file($credentialsFile)) {
                @unlink($credentialsFile);
            }
        }

        if ($exitCode !== 0) {
            if (is_file($backupPath)) {
                @unlink($backupPath);
            }

            throw new \RuntimeException("Failed to create database backup (mysqldump exited with {$exitCode})");
        }

        return $backupPath;
    }

    /**
     * Временный файл креденшелов для mysqldump.
     *
     * Права 0600 обязательны: файл содержит пароль БД. Состав содержимого —
     * в `credentialsFileContents()` (чистый метод, проверяется тестом).
     */
    private function writeCredentialsFile(string $host, int $port, string $user, string $password): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nabilet_dump_');

        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary credentials file for mysqldump');
        }

        if (file_put_contents($path, self::credentialsFileContents($host, $port, $user, $password)) === false) {
            throw new \RuntimeException('Cannot write the temporary credentials file for mysqldump');
        }

        @chmod($path, 0600);

        return $path;
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
        $remotePath = $remotePath ?? rtrim((string) config('services.yandex_disk.backups_path', '/backups'), '/') . "/{$fileName}";

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
        $remoteDir = rtrim((string) config('services.yandex_disk.backups_path', '/backups'), '/');

        return $this->uploadToYandexDisk($archivePath, "{$remoteDir}/full_{$timestamp}.zip");
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

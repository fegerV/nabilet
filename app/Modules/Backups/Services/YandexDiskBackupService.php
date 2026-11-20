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
     * Экземпляр шифровальщика: ключ из конфига `nabilet.backup.encryption_key`.
     */
    protected function encryptor(): BackupEncryptor
    {
        return new BackupEncryptor(
            (string) config('nabilet.backup.encryption_key', '')
        );
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
     * Самопроверка дампа: файл не пустой и в нём есть definition таблицы.
     *
     * Простая проверка размера недостаточна: файл в 2 КБ с одним `-- Header`
     * выглядит «непустым», но восстанавливать нечего. Ищем `CREATE TABLE` в
     * первых 128 КБ — структура дампа начинается с DDL, а читать
     * многосотмегабайтный файл целиком ради одной проверки незачем.
     */
    public static function dumpLooksValid(string $path): bool
    {
        if (! is_file($path) || filesize($path) < 1024) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $head = (string) fread($handle, 131072);
        } finally {
            fclose($handle);
        }

        return str_contains($head, 'CREATE TABLE');
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
     *
     * @param  bool  $encrypt  зашифровать перед выгрузкой (AES-256-CBC)
     */
    public function createDatabaseBackup(bool $encrypt = false)
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

        // mysqldump может завершиться с кодом 0 и при этом записать пустой или
        // урезанный файл (неверная БД, сбой диска, нет места). Выгружать такой
        // «бэкап» на Диск и удалять локальную копию нельзя — это потеря данных
        // без возможности восстановления. Проверка дешёвая, и делать её надо
        // ДО шифрования и выгрузки.
        if (! self::dumpLooksValid($backupPath)) {
            @unlink($backupPath);
            throw new \RuntimeException('Database backup failed its self-check: the dump is empty or has no table definitions.');
        }

        if ($encrypt) {
            $encrypted = $this->encryptor()->encryptFile($backupPath);
            @unlink($backupPath);

            return $encrypted;
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
     * Загрузка файла на Яндекс.Диск.
     *
     * @param  string  $filePath  локальный путь к файлу
     * @param  string|null  $remotePath  путь на Диске (по умолчанию — из конфига)
     * @param  bool  $deleteLocal  удалить локальный файл после успешной выгрузки
     * @return array{success: bool, remote_path: string, url: string|null}
     *
     * Файловый хэндл закрывается в `finally` (раньше `fopen`-хэндл утекал:
     * `curl_close` его не закрывает). На обе фазы (получение upload_url и сама
     * загрузка) выставлен таймаут и проверяется `curl_errno` — «тихая» пустая
     * строка вместо ответа или зависший сокет не должны выглядеть как успех.
     */
    public function uploadToYandexDisk($filePath, $remotePath = null, bool $deleteLocal = true)
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $fileName = basename($filePath);
        $remotePath = $remotePath ?? rtrim((string) config('services.yandex_disk.backups_path', '/backups'), '/') . "/{$fileName}";

        // 1. Получаем upload_url.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->uploadUrl . "?path=" . urlencode($remotePath) . "&overwrite=true");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: OAuth {$this->oauthToken}"]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || ! $response) {
            throw new \RuntimeException('Failed to get upload URL from Yandex Disk');
        }

        $data = json_decode((string) $response, true);

        if (empty($data['upload_url'])) {
            throw new \RuntimeException('Failed to get upload URL from Yandex Disk');
        }

        // 2. Загружаем файл.
        $fh = fopen($filePath, 'rb');

        if ($fh === false) {
            throw new \RuntimeException("Cannot open file for upload: {$filePath}");
        }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $data['upload_url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_PUT, true);
            curl_setopt($ch, CURLOPT_INFILE, $fh);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: OAuth {$this->oauthToken}"]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
        } finally {
            fclose($fh);
        }

        if ($errno !== 0 || ($httpCode !== 201 && $httpCode !== 200)) {
            throw new \RuntimeException('Failed to upload file to Yandex Disk');
        }

        if ($deleteLocal) {
            @unlink($filePath);
        }

        return [
            'success' => true,
            'remote_path' => $remotePath,
            'url' => $data['href'] ?? null,
        ];
    }

    /**
     * Проверка токена: дешёвый запрос к API Диска без записи файлов.
     *
     * Возвращает ok=false с текстом ошибки вместо исключения — админке
     * нужно показать понятную причину («токен истёк», «нет прав»), а не 500.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function testConnection(): array
    {
        $token = (string) ($this->oauthToken ?? '');
        if ($token === '') {
            return ['ok' => false, 'error' => 'Токен Яндекс.Диска не задан.'];
        }

        $ch = curl_init('https://cloud-api.yandex.net/v1/disk?fields=type,free_space');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: OAuth {$token}"]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return ['ok' => false, 'error' => 'Не удалось связаться с API Диска: '.$error];
        }
        if ($status === 401 || $status === 403) {
            return [
                'ok' => false,
                'error' => 'Токен недействителен или у него нет прав на Диск (HTTP '.$status.').',
            ];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Диск ответил HTTP '.$status.'.'];
        }

        $data = json_decode((string) $response, true);
        if (! is_array($data) || ($data['type'] ?? null) !== 'dir') {
            return ['ok' => false, 'error' => 'Диск вернул неожиданный ответ.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Бэкап только файлов системы: загруженные изображения, документы,
     * а также конфигурация (.env) — но без дампа БД.
     *
     * .env попадает в архив только при заданном ключе шифрования: в противном
     * случае архив с секретами уехал бы на внешний диск в открытом виде.
     *
     * @return array{success: bool, remote_path: string, url: string|null}
     */
    public function createFilesBackup(bool $deleteLocal = true, bool $encrypt = false)
    {
        $timestamp = date('Y-m-d_H-i-s');
        $workDir = storage_path("app/backups/files_{$timestamp}");

        if (! is_dir($workDir) && ! mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            throw new \RuntimeException("Cannot create the backup directory: {$workDir}");
        }

        try {
            $this->copyDirectory(storage_path('app/public'), "{$workDir}/storage_public");
            $configIncluded = $this->copyConfig($workDir, $encrypt);

            $archivePath = "{$workDir}.zip";
            $this->createZip($workDir, $archivePath);

            $uploadPath = $archivePath;
            $remoteName = "files_{$timestamp}.zip";
            if ($encrypt) {
                $uploadPath = $this->encryptor()->encryptFile($archivePath);
                @unlink($archivePath);
                $remoteName .= '.enc';
            }

            $remoteDir = $this->remoteBackupsDir();

            return $this->uploadToYandexDisk($uploadPath, "{$remoteDir}/{$remoteName}", $deleteLocal)
                + ['config_included' => $configIncluded];
        } finally {
            $this->deleteDirectory($workDir);
        }
    }

    /**
     * Скопировать .env в архив. Без ключа шифрования секрет НЕ выносится
     * за пределы хостинга — вместо этого в архив кладётся пояснение.
     */
    private function copyConfig(string $workDir, bool $encrypt): bool
    {
        $envPath = base_path('.env');

        if (! $encrypt || ! is_file($envPath)) {
            file_put_contents(
                "{$workDir}/CONFIG-NOT-INCLUDED.txt",
                "Файл .env не включён: задайте NABILET_BACKUP_ENCRYPTION_KEY, "
                ."чтобы копировать конфигурацию с секретами в зашифрованном архиве.\n",
            );

            return false;
        }

        copy($envPath, "{$workDir}/env.txt");

        return true;
    }

    /**
     * Полный бэкап системы (БД + файлы).
     *
     * @param  bool  $deleteLocal  удалить локальный архив после успешной выгрузки
     * @param  bool  $encrypt  зашифровать архив перед выгрузкой (AES-256-CBC)
     */
    public function createFullBackup(bool $deleteLocal = true, bool $encrypt = false)
    {
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = storage_path("app/backups/full_{$timestamp}");
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Бэкап БД
        $dbBackupPath = $this->createDatabaseBackup();
        rename($dbBackupPath, "{$backupDir}/database.sql");

        // Бэкап файлов (storage, public uploads) и конфигурации.
        $this->copyDirectory(storage_path('app/public'), "{$backupDir}/storage_public");
        $this->copyConfig($backupDir, $encrypt);

        // Архивируем
        $archivePath = "{$backupDir}.zip";
        $this->createZip($backupDir, $archivePath);

        // Очищаем временную директорию
        $this->deleteDirectory($backupDir);

        // Шифрование архива перед выгрузкой.
        $uploadPath = $archivePath;
        $remoteName = "full_{$timestamp}.zip";

        if ($encrypt) {
            $uploadPath = $this->encryptor()->encryptFile($archivePath);
            @unlink($archivePath);
            $remoteName .= '.enc';
        }

        // Загружаем на Яндекс.Диск
        $remoteDir = rtrim((string) config('services.yandex_disk.backups_path', '/backups'), '/');

        return $this->uploadToYandexDisk($uploadPath, "{$remoteDir}/{$remoteName}", $deleteLocal);
    }

    /**
     * Каталог бэкапов на Яндекс.Диске (без завершающего слэша).
     */
    public function remoteBackupsDir(): string
    {
        return rtrim((string) config('services.yandex_disk.backups_path', '/backups'), '/');
    }

    /**
     * Список резервных копий на Яндекс.Диске.
     *
     * Возвращаются только файлы, чьё имя соответствует формату бэкапа:
     * посторонние файлы в каталоге не должны даже рассматриваться как
     * кандидаты на удаление. Отсутствие каталога — не ошибка (первый бэкап его
     * создаст), а вот сетевой сбой или 401 — ошибка: ротация fail-closed, при
     * неясном ответе ничего не удаляется.
     *
     * @return list<array{name: string, path: string}>
     */
    public function listRemoteBackups(): array
    {
        $token = (string) ($this->oauthToken ?? '');

        if ($token === '') {
            throw new \RuntimeException('YANDEX_DISK_OAUTH_TOKEN is not configured; cannot list remote backups.');
        }

        $dir = $this->remoteBackupsDir();
        $offset = 0;
        $limit = 200;
        $rawItems = [];

        while (true) {
            $url = 'https://cloud-api.yandex.net/v1/disk/resources?' . http_build_query([
                'path' => $dir,
                'limit' => $limit,
                'offset' => $offset,
                'fields' => '_embedded.total,_embedded.items.name,_embedded.items.path,_embedded.items.type',
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: OAuth {$token}"]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);

            // Каталога ещё нет — это не ошибка.
            if ($httpCode === 404) {
                return [];
            }

            if ($errno !== 0 || $httpCode !== 200 || ! is_string($response)) {
                throw new \RuntimeException("Failed to list remote backups (HTTP {$httpCode}).");
            }

            $data = json_decode($response, true);

            if (! is_array($data)) {
                throw new \RuntimeException('Failed to parse the Yandex Disk backup listing.');
            }

            $embedded = is_array($data['_embedded'] ?? null) ? $data['_embedded'] : [];
            $page = is_array($embedded['items'] ?? null) ? $embedded['items'] : [];
            $total = (int) ($embedded['total'] ?? count($page));

            foreach ($page as $item) {
                if (is_array($item)) {
                    $rawItems[] = $item;
                }
            }

            $offset += $limit;

            if ($page === [] || $offset >= $total) {
                break;
            }
        }

        $result = [];

        foreach ($rawItems as $item) {
            $name = (string) ($item['name'] ?? '');
            $path = (string) ($item['path'] ?? '');

            if (($item['type'] ?? '') !== 'file' || ! BackupRotation::isBackupName($name) || $path === '') {
                continue;
            }

            $result[] = ['name' => $name, 'path' => $path];
        }

        return $result;
    }

    /**
     * Удалить резервную копию на Яндекс.Диске.
     *
     * Путь ДОЛЖЕН лежать в настроенном каталоге бэкапов и заканчиваться именем
     * в формате бэкапа — сервис не должен уметь удалять произвольные файлы
     * Диска, даже если его об этом попросят. 404 трактуется как «уже удалено»:
     * повторный запуск ротации идемпотентен.
     */
    public function deleteRemoteBackup(string $path): void
    {
        $token = (string) ($this->oauthToken ?? '');

        if ($token === '') {
            throw new \RuntimeException('YANDEX_DISK_OAUTH_TOKEN is not configured; cannot delete remote backups.');
        }

        $dir = $this->remoteBackupsDir();
        $name = basename($path);

        if (! BackupRotation::isBackupName($name) || ! str_contains($path, $dir . '/')) {
            throw new \RuntimeException("Refusing to delete a non-backup remote path: {$path}");
        }

        $url = 'https://cloud-api.yandex.net/v1/disk/resources?' . http_build_query([
            'path' => $path,
            'permanently' => 'true',
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: OAuth {$token}"]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($httpCode === 404) {
            return;
        }

        if ($errno !== 0 || ! in_array($httpCode, [200, 202, 204], true)) {
            throw new \RuntimeException("Failed to delete remote backup (HTTP {$httpCode}).");
        }
    }

    /**
     * Ротация локальных копий в `storage/app/backups`.
     *
     * @param  list<string>  $protect  имена, которые нельзя удалять
     * @return array{deleted: list<string>, failed: array<string, string>, kept: int}
     */
    public function rotateLocalBackups(int $keep, array $protect = [], bool $dryRun = false): array
    {
        $dir = storage_path('app/backups');

        if (! is_dir($dir)) {
            return ['deleted' => [], 'failed' => [], 'kept' => 0];
        }

        $names = [];

        foreach (glob($dir . '/*') ?: [] as $path) {
            // Директории (недоделанный `full_*`) и посторонние файлы не трогаем.
            if (is_file($path)) {
                $names[] = basename($path);
            }
        }

        $valid = array_values(array_filter($names, [BackupRotation::class, 'isBackupName']));
        $expired = BackupRotation::selectExpired($valid, $keep, $protect);

        $deleted = [];
        $failed = [];

        foreach ($expired as $name) {
            $path = $dir . '/' . $name;

            if ($dryRun) {
                $deleted[] = $name;
                continue;
            }

            if (@unlink($path)) {
                $deleted[] = $name;
            } else {
                $failed[$name] = 'unlink failed';
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'kept' => count($valid) - count($expired),
        ];
    }

    /**
     * Ротация копий на Яндекс.Диске.
     *
     * @param  list<string>  $protect  имена, которые нельзя удалять
     * @return array{deleted: list<string>, failed: array<string, string>, kept: int}
     */
    public function rotateRemoteBackups(int $keep, array $protect = [], bool $dryRun = false): array
    {
        $items = $this->listRemoteBackups();

        $paths = [];

        foreach ($items as $item) {
            $paths[$item['name']] = $item['path'];
        }

        $expired = BackupRotation::selectExpired(array_keys($paths), $keep, $protect);

        $deleted = [];
        $failed = [];

        foreach ($expired as $name) {
            if ($dryRun) {
                $deleted[] = $name;
                continue;
            }

            try {
                $this->deleteRemoteBackup($paths[$name]);
                $deleted[] = $name;
            } catch (\Throwable $e) {
                $failed[$name] = $e->getMessage();
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'kept' => count($paths) - count($expired),
        ];
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

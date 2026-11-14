<?php

declare(strict_types=1);

// Роутер для `php -S 127.0.0.1:8088 router-smoke.php`.
// Точно имитирует правила поставляемого .htaccess (см. .htaccess в корне):
//   1. FilesMatch "^\.". dotfiles -> 403
//   2. Системные каталоги -> 403
//   3. Реальные файлы -> статика (return false)
//   4. /storage/... -> storage/app/public/... (только существующие файлы)
//   5. Всё остальное -> фронт-контроллер index.php
// ВНИМАНИЕ: сборщик зипа чистит site/ — после `build-hosting-zip.ps1`
// пересоздавайте этот файл перед запуском стенда.

$base = __DIR__;
$path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

// 1. Dotfiles (FilesMatch "^\.")
if ($path !== '/' && (str_starts_with($path, '.') || str_contains($path, '/.'))) {
    http_response_code(403);
    exit('Forbidden');
}

// 2. Системные каталоги
if (preg_match('#^/(app|bootstrap|config|database|lang|public|resources|routes|dist|vendor)(/|$)#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

// 3. Media: /storage/{path} -> storage/app/public/{path} (только при -f)
if (preg_match('#^/storage/(.+)$#', $path, $m)) {
    $target = $base . '/storage/app/public/' . $m[1];
    if (is_file($target)) {
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($target));
        readfile($target);
        exit;
    }
    // не статика: падение во фронт-контроллер (фирменная 404)
    require $base . '/index.php';
    exit;
}

// 4. Реальные файлы
if ($path !== '/' && is_file($base . $path)) {
    return false; // статика средствами встроенного сервера
}

// 5. Фронт-контроллер
require $base . '/index.php';

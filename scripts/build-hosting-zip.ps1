<#
.SYNOPSIS
    Сборка установочного архива NABILET для шаред-хостинга (WordPress-style).

.DESCRIPTION
    Корень архива РАВЕН docroot: после распаковки в public_html там лежат
    index.php, .htaccess, .user.ini, robots.txt, favicon.svg и системные
    каталоги (app/, config/, vendor/, storage/, ...) — «как на вордпрессе».
    Файловый менеджер хостинга умеет распаковывать только внутрь public_html,
    поэтому внутри архива нет папки-обёртки.

    Состав:
      - системные каталоги: app, bootstrap, config, database, lang, public,
        resources, routes, dist, vendor;
      - storage с демо-медиа, без runtime-мусора (логи/кэш/бэкапы);
      - legacy-статика public_html/build -> build/ (отдаётся как есть);
      - статика из public/ -> корень архива (robots.txt, favicon.svg, images/);
      - корневые index.php (шим) и .htaccess (deny системных каталогов +
        маппинг /storage/... -> storage/app/public/...);
      - .user.ini, .env.example, composer.json/lock, CHANGELOG.md.

.PARAMETER Version
    Версия для имени архива, например 1.0.2.

.EXAMPLE
    powershell -File scripts\build-hosting-zip.ps1 -Version 1.0.2
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$Version
)

$ErrorActionPreference = "Stop"
$repo = Split-Path -Parent $PSScriptRoot
$date = Get-Date -Format "yyyyMMdd"
$zipName = "nabilet-hosting-v$Version-$date.zip"
$zipPath = Join-Path $repo "releases\$zipName"
$stageRoot = Join-Path $repo ".tmp-release-stage"
$stage = Join-Path $stageRoot "site"

function Copy-Tree($from, $to, $purge = $false) {
    $args = @($from, $to, "/E", "/XJ", "/NFL", "/NDL", "/NJH", "/NJS", "/NP")
    if ($purge) { $args += "/PURGE" }
    robocopy @args | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed for '$from' (code $LASTEXITCODE)" }
}

# --- 1. Чистый staging -------------------------------------------------------
if (Test-Path $stageRoot) { Remove-Item -Recurse -Force $stageRoot }
New-Item -ItemType Directory -Force $stage | Out-Null

# --- 2. Системные каталоги, витрина, vendor ----------------------------------
# /XJ: не ходить внутрь junction-ов (public/storage -> storage/app/public),
# иначе в архив уезжает дубль медиа как обычный каталог.
foreach ($d in "app", "bootstrap", "config", "database", "lang", "public", "resources", "routes", "dist", "vendor") {
    Copy-Tree (Join-Path $repo $d) (Join-Path $stage $d)
}

# --- 3. storage: демо-медиа остаются, runtime-мусор не едет -------------------
Copy-Tree (Join-Path $repo "storage") (Join-Path $stage "storage")
$cleaned = 0
Get-ChildItem "$stage\storage\logs" -File -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne ".gitignore" } | ForEach-Object { Remove-Item $_.FullName; $cleaned++ }
Get-ChildItem "$stage\storage\framework" -Recurse -File -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne ".gitignore" } | ForEach-Object { Remove-Item $_.FullName; $cleaned++ }
foreach ($p in "storage\app\checkin", "storage\app\_probe_mail.php",
    "storage\app\backups\db_test_2026-10-30.sql", "storage\app\backups\legit.sql",
    "storage\app\private\backups\db_test_2026-10-30.sql", "storage\app\private\backups\legit.sql") {
    $f = Join-Path $stage $p
    if (Test-Path $f) { Remove-Item -Recurse -Force $f; $cleaned++ }
}
foreach ($p in "storage\framework", "storage\framework\cache", "storage\framework\cache\data",
    "storage\framework\sessions", "storage\framework\testing", "storage\framework\views",
    "storage\logs", "bootstrap\cache") {
    $dir = Join-Path $stage $p
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force $dir | Out-Null }
    if (-not (Test-Path "$dir\.gitignore")) { Set-Content "$dir\.gitignore" "*`n!.gitignore" }
}

# --- 4. Legacy-статика и настройки из public_html -----------------------------
# build/ остаётся в docroot: /build/assets/* отдаётся статикой (legacy).
Copy-Tree (Join-Path $repo "public_html\build") (Join-Path $stage "build")
Copy-Item (Join-Path $repo "public_html\.user.ini") $stage

# --- 5. Статика docroot из public/ -------------------------------------------
# robots.txt, favicon.svg и og-картинки: локально их отдаёт public/, в архиве
# они лежат в корне docroot (WordPress-style) и отдаются Apache напрямую.
Copy-Item (Join-Path $repo "public\robots.txt") $stage
Copy-Item (Join-Path $repo "public\favicon.svg") $stage
# installer-tailwind.js: локальная копия Tailwind Play CDN 3.4 (v1.0.4) —
# мастер установки не должен зависеть от cdn.tailwindcss.com.
Copy-Item (Join-Path $repo "public\installer-tailwind.js") $stage
Copy-Tree (Join-Path $repo "public\images") (Join-Path $stage "images")

# --- 6. Корневые файлы приложения --------------------------------------------
foreach ($f in ".env.example", ".gitignore", "CHANGELOG.md", "artisan",
    "composer.json", "composer.lock") {
    Copy-Item (Join-Path $repo $f) $stage
}

# Корневой index.php — шим на канонический фронт-контроллер public/index.php.
# Внутри public/index.php все пути относительные (../vendor, ../bootstrap),
# поэтому дерево целиком живёт в docroot. Только ASCII: BOM перед <?php
# недопустим (Set-Content UTF8 в PS 5.1 пишет BOM).
$shim = @'
<?php

declare(strict_types=1);

// NABILET Core - shared hosting entrypoint (WordPress-style layout).
//
// DocumentRoot IS the application root: index.php, .htaccess, .user.ini,
// robots.txt, favicon.svg and the system directories (app/, config/, vendor/,
// storage/, ...) all live directly in public_html/, like WordPress.
//
// This thin entrypoint keeps a single source of truth: the canonical Laravel
// front controller in public/index.php. Every path inside it resolves relative
// to public/ (../vendor, ../bootstrap/app.php, ../storage), i.e. one level up,
// which is exactly where those directories are.
require __DIR__ . '/public/index.php';
'@
Set-Content -Path (Join-Path $stage "index.php") -Value $shim -Encoding ASCII

# Корневой .htaccess. Только ASCII (см. комментарий выше): Apache 2.4.
$htaccess = @'
# NABILET Core - shared hosting front controller (WordPress-style layout)
#
# Document root IS the application root: after unpacking the release archive
# into public_html/, index.php, .htaccess, .user.ini, robots.txt, favicon.svg
# and the system directories (app/, config/, vendor/, storage/, ...) all live
# at this level, like WordPress.
#
# Served statically:  /build/...   (legacy compiled assets)
#                     /favicon.svg, /images/..., /robots.txt
# Mapped to storage:  /storage/... -> storage/app/public/...  (media files)
# Routed to Laravel:  everything else -> index.php
#                     (/assets/* serves dist/assets from routes/web.php)

<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Pass the Authorization header through to PHP (Sanctum / bearer-token auth).
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Strip a trailing slash (except for real directories).
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Media: /storage/{path} -> storage/app/public/{path}.
    #
    # WHY: on shared hosting DocumentRoot is public_html/ itself now, and the
    # Laravel symlink public/storage is NOT inside it (plus symlinks are
    # unreliable on shared hosts), so /storage/... URLs are mapped here.
    # The -f condition prevents a rewrite loop: if the target file does not
    # exist, the request falls through to the front controller (branded 404)
    # instead of stacking storage/app/public/ prefixes.
    RewriteCond %{DOCUMENT_ROOT}/storage/app/public/$1 -f
    RewriteRule ^storage/(.+)$ storage/app/public/$1 [L]

    # System directories are not reachable over HTTP directly: PHP sources,
    # migrations, the compiled storefront (served via routes) and anything
    # else that must not be fetched or executed as raw PHP.
    RewriteRule ^(app|bootstrap|config|database|lang|public|resources|routes|dist|vendor)(/|$) - [F,L]

    # Send all other requests to the front controller.
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

# Block dotfiles (stray .env copies, .git, .user.ini itself) from being served.
# PHP-FPM still reads .user.ini from disk, this only blocks HTTP access.
# Apache 2.4 syntax.
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
'@
Set-Content -Path (Join-Path $stage ".htaccess") -Value $htaccess -Encoding ASCII

# --- 7. Архив ----------------------------------------------------------------
if (Test-Path $zipPath) { Remove-Item $zipPath }
Push-Location $stage
try {
    tar -a -cf $zipPath app bootstrap config database dist images lang public resources routes storage vendor build `
        ".env.example" ".gitignore" ".htaccess" ".user.ini" "CHANGELOG.md" "artisan" "composer.json" "composer.lock" `
        "favicon.svg" "index.php" "installer-tailwind.js" "robots.txt"
    if ($LASTEXITCODE -ne 0) { throw "tar failed (code $LASTEXITCODE)" }
}
finally {
    Pop-Location
}

# --- 8. Верификация ----------------------------------------------------------
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$top = $zip.Entries | ForEach-Object { ($_.FullName -split '/')[0] } | Sort-Object -Unique
$backslashes = ($zip.Entries | Where-Object { $_.FullName -like '*\*' }).Count
$zip.Dispose()
$sizeMB = [math]::Round((Get-Item $zipPath).Length / 1MB, 1)

Write-Host "OK: $zipPath ($sizeMB MB)"
Write-Host "Top-level entries: $($top -join ', ')"
Write-Host "Backslash entries: $backslashes"

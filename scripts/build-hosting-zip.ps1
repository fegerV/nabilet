<#
.SYNOPSIS
    Build a clean shared-hosting release. Extract into the SITE DIRECTORY,
    one level above public_html. Never extract into the document root.
.DESCRIPTION
    Uses composer.lock for production dependencies and an empty storage tree.
    Does not copy local vendor, environment, media, logs, backups or cached config.
    Build the frontend with npm run build first. See docs/deploy-timeweb.md.
#>
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$')]
    [string]$Version
)

$ErrorActionPreference = 'Stop'
$repo = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$releaseDir = Join-Path $repo 'releases'
$stageRoot = Join-Path $repo '.tmp-release-stage'
# Previous builds left a full vendor/ tree in .tmp-release-stage (57k files),
# which makes repo-wide linters scan a release copy instead of the sources.
# Clean stale stages before creating this run's one.
if (Test-Path -LiteralPath $stageRoot) {
    Get-ChildItem -LiteralPath $stageRoot -Directory | Remove-Item -Recurse -Force
}
$stage = Join-Path $stageRoot ('site-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $stage, $releaseDir | Out-Null
$zipPath = Join-Path $releaseDir ('nabilet-hosting-v' + $Version + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
if (Test-Path -LiteralPath $zipPath) { throw 'Release already exists; wait a second and retry.' }

function Copy-Tree([string]$Source, [string]$Destination) {
    if (-not (Test-Path -LiteralPath $Source)) { throw "Missing input: $Source" }
    & robocopy $Source $Destination /E /XJ /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed for $Source ($LASTEXITCODE)" }
}

$package = Get-Content -LiteralPath (Join-Path $repo 'package.json') -Raw | ConvertFrom-Json
if ($package.version -ne $Version) { throw 'Version must match package.json.' }
if (-not (Test-Path -LiteralPath (Join-Path $repo 'dist/index.html'))) { throw 'Run npm run build first.' }

foreach ($name in 'app', 'config', 'database', 'lang', 'resources', 'routes', 'dist') {
    Copy-Tree (Join-Path $repo $name) (Join-Path $stage $name)
}
New-Item -ItemType Directory -Force -Path (Join-Path $stage 'bootstrap/cache') | Out-Null
Get-ChildItem -LiteralPath (Join-Path $repo 'bootstrap') -File -Filter '*.php' |
    Copy-Item -Destination (Join-Path $stage 'bootstrap')

foreach ($name in '.env.example', 'CHANGELOG.md', 'artisan', 'composer.json', 'composer.lock') {
    Copy-Item -LiteralPath (Join-Path $repo $name) -Destination $stage
}

# Only known public files enter the document root. Exclude local diagnostic
# PHP files, junctions and obsolete development bundles.
New-Item -ItemType Directory -Force -Path (Join-Path $stage 'public'), (Join-Path $stage 'public_html') | Out-Null
Copy-Item -LiteralPath (Join-Path $repo 'public/index.php') -Destination (Join-Path $stage 'public')
foreach ($name in 'index.php', '.htaccess', '.user.ini') {
    Copy-Item -LiteralPath (Join-Path $repo "public_html/$name") -Destination (Join-Path $stage 'public_html')
}
foreach ($name in 'robots.txt', 'favicon.svg', 'installer-tailwind.js') {
    Copy-Item -LiteralPath (Join-Path $repo "public/$name") -Destination (Join-Path $stage 'public_html')
}
Copy-Tree (Join-Path $repo 'public/images') (Join-Path $stage 'public_html/images')
Copy-Tree (Join-Path $repo 'dist/assets') (Join-Path $stage 'public_html/assets')

foreach ($name in 'storage/app/public', 'storage/app/private', 'storage/logs',
    'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache') {
    $dir = Join-Path $stage $name
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    [IO.File]::WriteAllText((Join-Path $dir '.gitignore'), "*`n!.gitignore`n")
}

Push-Location $stage
try {
    & composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --no-interaction --no-progress
    if ($LASTEXITCODE -ne 0) { throw 'Production dependency installation failed.' }
    & composer check-platform-reqs --no-dev
    if ($LASTEXITCODE -ne 0) { throw 'Platform requirements failed.' }
    # Do not boot the application while packaging: no generated installation
    # key, package caches, database calls or environment from this workstation.
    & tar -a -cf $zipPath app bootstrap config database dist lang public public_html resources routes storage vendor `
        .env.example CHANGELOG.md artisan composer.json composer.lock
    if ($LASTEXITCODE -ne 0) { throw 'Archive creation failed.' }
} finally { Pop-Location }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $names = @($zip.Entries | ForEach-Object { $_.FullName.Replace('\', '/') })
    foreach ($name in $names) {
        if ($name -match '(^|/)\.env($|\.(?!example$))' -or
            ($name -match '^storage/.*[^/]$' -and $name -notmatch '/\.gitignore$') -or
            ($name -match '^bootstrap/cache/.*[^/]$' -and $name -notmatch '/\.gitignore$') -or
            $name -match '^vendor/(phpunit|phpstan|mockery|fakerphp|laravel/pint)/') {
            throw "Unsafe release entry: $name"
        }
    }
    if ($names -notcontains 'public_html/index.php' -or $names -contains 'index.php') {
        throw 'Incorrect document-root layout.'
    }
} finally { $zip.Dispose() }
$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText("$zipPath.sha256", "$hash  $([IO.Path]::GetFileName($zipPath))`n")
Write-Host "OK: $zipPath"
Write-Host "SHA256: $hash"
Write-Host 'Extract into the site directory ABOVE public_html. Storage contains no customer data.'

# The stage is a full release copy (vendor/, dist/, 9k files). Leaving it in
# the working tree slows down lint/test discovery on every later run.
Remove-Item -LiteralPath $stage -Recurse -Force

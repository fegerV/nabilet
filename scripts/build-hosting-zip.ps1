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

# --- One-click update manifest -------------------------------------------------
# Generated AFTER the sidecar so the manifest carries the verified hash of the
# final zip. The file is a "latest release" pointer in the same releases/ dir.
$releaseManifestPath = Join-Path $releaseDir 'manifest.json'
$phpMin = '8.3.0'
$phpMaxExclusive = '9.0.0'
$phpExtensions = @('curl', 'json', 'mbstring', 'openssl', 'pdo', 'zip') | Sort-Object
$channel = 'stable'
$artifactName = [IO.Path]::GetFileName($zipPath)
$sizeBytes = (Get-Item -LiteralPath $zipPath).Length
$releasedAt = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')

# Optional explicit upgrade path (comma-separated, trimmed). Empty means any
# lower version is accepted; downgrade itself is still blocked by the client.
$fromVersions = @()
if ($env:NABILET_UPDATE_FROM_VERSIONS) {
    $fromVersions = @($env:NABILET_UPDATE_FROM_VERSIONS -split ',' |
        ForEach-Object { $_.Trim() } |
        Where-Object { $_ -ne '' })
}

# Migration count: prefer the staged copy, fall back to the repository copy.
$migrationsDir = Join-Path $stage 'database/migrations'
if (-not (Test-Path -LiteralPath $migrationsDir)) {
    $migrationsDir = Join-Path $repo 'database/migrations'
}
$migrations = 0
if (Test-Path -LiteralPath $migrationsDir) {
    $migrations = @(Get-ChildItem -LiteralPath $migrationsDir -File).Count
}

# Force real JSON arrays (ConvertTo-Json collapses single-element arrays).
function ConvertTo-JsonLiteral {
    param([AllowNull()][object]$Value)
    if ($null -eq $Value) { return 'null' }
    return (ConvertTo-Json -InputObject $Value -Compress)
}
function ConvertTo-JsonArrayLiteral {
    param([AllowNull()][object[]]$Values)
    if ($null -eq $Values -or $Values.Count -eq 0) { return '[]' }
    return '[' + (($Values | ForEach-Object { ConvertTo-JsonLiteral $_ }) -join ',') + ']'
}

# Signature is optional: only when NABILET_UPDATE_PRIVATE_KEY points to a PEM
# key. Never fail the release because signing is unavailable — emit '' instead
# (the release then works via manual upload + sha256 verification only).
$signature = ''
$privateKey = $env:NABILET_UPDATE_PRIVATE_KEY
if ($privateKey) {
    if (-not (Test-Path -LiteralPath $privateKey)) {
        Write-Warning "NABILET_UPDATE_PRIVATE_KEY not found: $privateKey - emitting an empty signature."
    } elseif (-not (Get-Command openssl -ErrorAction SilentlyContinue)) {
        Write-Warning 'openssl is unavailable - emitting an empty signature (manual upload + sha256 only).'
    } else {
        try {
            # Exact canonical bytes shared with the PHP verifier: UTF-8, LF,
            # no trailing newline, sorted extensions, versions as given.
            $canonical = @(
                'NABILET-UPDATE-V1'
                'schema=1'
                "version=$Version"
                "released_at=$releasedAt"
                "channel=$channel"
                "artifact=$artifactName"
                "sha256=$hash"
                "size_bytes=$sizeBytes"
                "php_min=$phpMin"
                "php_max=$phpMaxExclusive"
                'extensions=' + ($phpExtensions -join ',')
                'from_versions=' + ($fromVersions -join ',')
                "migrations=$migrations"
            ) -join "`n"

            $canonicalPath = Join-Path $stage '.update-canonical.bin'
            $signaturePath = Join-Path $stage '.update-signature.bin'
            $utf8NoBom = [Text.UTF8Encoding]::new($false)
            [IO.File]::WriteAllBytes($canonicalPath, $utf8NoBom.GetBytes($canonical))

            & openssl dgst -sha256 -sign $privateKey -out $signaturePath $canonicalPath 2>$null
            if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $signaturePath)) {
                throw "openssl dgst failed (exit $LASTEXITCODE)."
            }
            $signature = [Convert]::ToBase64String([IO.File]::ReadAllBytes($signaturePath))
        } catch {
            Write-Warning "Update manifest signing failed ($($_.Exception.Message)) - emitting an empty signature."
            $signature = ''
        }
    }
}

$manifestJson = @"
{
  "schema": 1,
  "version": $(ConvertTo-JsonLiteral $Version),
  "released_at": $(ConvertTo-JsonLiteral $releasedAt),
  "channel": $(ConvertTo-JsonLiteral $channel),
  "artifact": $(ConvertTo-JsonLiteral $artifactName),
  "sha256": $(ConvertTo-JsonLiteral $hash),
  "size_bytes": $sizeBytes,
  "php": {
    "min": $(ConvertTo-JsonLiteral $phpMin),
    "max_exclusive": $(ConvertTo-JsonLiteral $phpMaxExclusive),
    "extensions": $(ConvertTo-JsonArrayLiteral $phpExtensions)
  },
  "from_versions": $(ConvertTo-JsonArrayLiteral $fromVersions),
  "migrations": $migrations,
  "signature": $(ConvertTo-JsonLiteral $signature)
}
"@
[IO.File]::WriteAllText($releaseManifestPath, $manifestJson + "`n", [Text.UTF8Encoding]::new($false))

# Self-check: the manifest must parse and carry the sidecar's hash.
$parsedManifest = Get-Content -LiteralPath $releaseManifestPath -Raw | ConvertFrom-Json
if ($parsedManifest.sha256 -ne $hash) {
    throw 'Manifest verification failed: sha256 differs from the .sha256 sidecar.'
}

Write-Host "OK: $zipPath"
Write-Host "SHA256: $hash"
Write-Host "Manifest: $releaseManifestPath"
Write-Host 'Extract into the site directory ABOVE public_html. Storage contains no customer data.'

# The stage is a full release copy (vendor/, dist/, 9k files). Leaving it in
# the working tree slows down lint/test discovery on every later run.
Remove-Item -LiteralPath $stage -Recurse -Force

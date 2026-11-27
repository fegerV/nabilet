<#
.SYNOPSIS
    Build a clean shared-hosting release. Extract into the SITE DIRECTORY,
    one level above public_html. Never extract into the document root.

.DESCRIPTION
    Thin Windows entry point. The actual build lives in the portable PHP
    builder (scripts/build-hosting-zip.php) so local Windows builds and the
    GitHub Actions runner produce the same archive from the same rules:
    explicit file lists, production dependencies from composer.lock, no
    secrets, no runtime storage, no dev packages, SHA-256 and a signed
    manifest when a private key is provided.

    Build the frontend first: npm run build
#>
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$')]
    [string]$Version
)

$ErrorActionPreference = 'Stop'
$repo = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$builder = Join-Path $PSScriptRoot 'build-hosting-zip.php'

& php $builder "--version=$Version"
if ($LASTEXITCODE -ne 0) {
    throw "Hosting archive build failed (exit $LASTEXITCODE)."
}

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$release = Join-Path $root 'release'
$site = Join-Path $release 'site'
$archive = Join-Path $release 'pmas-production.zip'

Push-Location $root
try {
    $npm = (Get-Command npm.cmd -ErrorAction Stop).Source
    $composerPhar = 'C:\composer\composer.phar'
    if (-not (Test-Path $composerPhar)) { throw 'Composer PHAR was not found at C:\composer\composer.phar.' }

    & $npm test
    if ($LASTEXITCODE -ne 0) { throw 'Frontend tests failed.' }
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php -and (Test-Path 'C:\xampp\php\php.exe')) { $php = Get-Item 'C:\xampp\php\php.exe' }
    if (-not $php) { throw 'PHP was not found.' }
    & $php.Source 'vendor/bin/phpunit' '--configuration' 'phpunit.xml.dist'
    if ($LASTEXITCODE -ne 0) { throw 'PHP tests failed.' }
    & $npm run build
    if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
    if (Test-Path $site) { Remove-Item -LiteralPath $site -Recurse -Force }
    New-Item -ItemType Directory -Path $site | Out-Null

    Copy-Item 'dist\*' $site -Recurse -Force
    foreach ($directory in @('api', 'assets', 'components', 'dashboards', 'includes', 'reports')) {
        if (Test-Path $directory) { Copy-Item $directory $site -Recurse -Force }
    }
    foreach ($file in @('.htaccess', 'index.php', 'login.php', 'logout.php')) {
        if (Test-Path $file) { Copy-Item $file $site -Force }
    }
    $cliDirectory = Join-Path $site 'bin'
    New-Item -ItemType Directory -Force -Path $cliDirectory | Out-Null
    Copy-Item 'scripts/Check-Database.php', 'scripts/Bootstrap-TestAccounts.php' $cliDirectory -Force

    Get-ChildItem $site -Recurse -File -Include '*.bak','*.log','*.sql','*.dump' | Remove-Item -Force
    $uploads = Join-Path $site 'assets/uploads'
    if (Test-Path $uploads) { Remove-Item -LiteralPath $uploads -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $uploads | Out-Null

    $previousVendorDir = $env:COMPOSER_VENDOR_DIR
    try {
        $env:COMPOSER_VENDOR_DIR = (Join-Path $site 'vendor')
        & $php.Source -d extension=gd $composerPhar install --no-dev --optimize-autoloader --no-interaction
        if ($LASTEXITCODE -ne 0) { throw 'Composer production install failed.' }
    } finally {
        $env:COMPOSER_VENDOR_DIR = $previousVendorDir
    }
    & (Join-Path $PSScriptRoot 'Test-ProductionReadiness.ps1') -SiteRoot $site

    if (Test-Path $archive) { Remove-Item -LiteralPath $archive -Force }
    Compress-Archive -Path (Join-Path $site '*') -DestinationPath $archive -CompressionLevel Optimal
    Write-Host "Production bundle created: $archive"
} finally {
    Pop-Location
}

param(
    [string]$SiteUrl = 'https://ndmc-appraisia.wuaze.com/PMAS',
    [string]$DatabaseHost = 'sql201.infinityfree.com',
    [string]$DatabaseName = 'if0_42426676_PMAS',
    [string]$DatabaseUser = 'if0_42426676'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$bundleRoot = Join-Path $root 'release\infinityfree'
$siteRoot = Join-Path $bundleRoot 'PMAS'
$archive = Join-Path $bundleRoot 'PMAS-InfinityFree.zip'

if (Test-Path $siteRoot) {
    Remove-Item -LiteralPath $siteRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $siteRoot -Force | Out-Null

# The production frontend already contains the /PMAS/ asset base path.
Copy-Item (Join-Path $root 'dist\*') $siteRoot -Recurse -Force

foreach ($directory in @('api', 'components', 'dashboards', 'includes', 'reports', 'vendor')) {
    $source = Join-Path $root $directory
    if (Test-Path $source) {
        Copy-Item $source $siteRoot -Recurse -Force
    }
}

# Copy legacy/static resources, uploaded department images, and profiles without
# copying obsolete hashed frontend builds from the project-root assets folder.
$assetsRoot = Join-Path $siteRoot 'assets'
foreach ($directory in @('css', 'images', 'js', 'uploads')) {
    $source = Join-Path $root "assets\$directory"
    if (Test-Path $source) {
        Copy-Item $source $assetsRoot -Recurse -Force
    }
}

foreach ($file in @('index.php', 'login.php', 'logout.php')) {
    Copy-Item (Join-Path $root $file) $siteRoot -Force
}

$sourceHtaccess = Get-Content (Join-Path $root '.htaccess') -Raw
$sha256 = [System.Security.Cryptography.SHA256]::Create()
try {
    $legacyDataKey = [System.BitConverter]::ToString(
        $sha256.ComputeHash(
            [System.Text.Encoding]::UTF8.GetBytes('pmas_db_cleanrootDIPASCAF')
        )
    ).Replace('-', '').ToLowerInvariant()
} finally {
    $sha256.Dispose()
}

$environment = @"

# InfinityFree production configuration. Replace the password placeholder
# privately in the online File Manager after uploading this archive.
SetEnv PMAS_ENV "production"
SetEnv PMAS_APP_URL "$SiteUrl"
SetEnv PMAS_BASE_PATH "/PMAS"
SetEnv PMAS_DB_HOST "$DatabaseHost"
SetEnv PMAS_DB_PORT "3306"
SetEnv PMAS_DB_NAME "$DatabaseName"
SetEnv PMAS_DB_USER "$DatabaseUser"
SetEnv PMAS_DB_PASS "REPLACE_WITH_YOUR_VPANEL_PASSWORD"
SetEnv PMAS_DATA_KEY "$legacyDataKey"
"@

Set-Content -LiteralPath (Join-Path $siteRoot '.htaccess') -Value ($environment + $sourceHtaccess) -Encoding UTF8

Get-ChildItem $siteRoot -Recurse -File -Include '*.sql','*.log','*.bak','*.dump','*.ps1' |
    Remove-Item -Force

if (Test-Path $archive) {
    Remove-Item -LiteralPath $archive -Force
}
Compress-Archive -Path $siteRoot -DestinationPath $archive -CompressionLevel Optimal

$files = Get-ChildItem $siteRoot -Recurse -File
$bytes = ($files | Measure-Object -Property Length -Sum).Sum
Write-Output ([pscustomobject]@{
    Archive = $archive
    Files = $files.Count
    UncompressedMB = [math]::Round($bytes / 1MB, 2)
    ArchiveMB = [math]::Round((Get-Item $archive).Length / 1MB, 2)
})

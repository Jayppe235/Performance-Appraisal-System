param([string]$SiteRoot)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $SiteRoot) { $SiteRoot = Join-Path $root 'release/site' }

$required = @('index.html', '.htaccess', 'api/health.php', 'includes/config.php', 'includes/credentials.php', 'vendor/autoload.php')
foreach ($item in $required) {
    if (-not (Test-Path (Join-Path $SiteRoot $item))) { throw "Missing production file: $item" }
}

$forbidden = @('src', 'tests', 'node_modules', 'database', 'setup.php', '.env', '.env.local', 'composer.json', 'package.json')
foreach ($item in $forbidden) {
    if (Test-Path (Join-Path $SiteRoot $item)) { throw "Forbidden deployment item found: $item" }
}

$secretPattern = '(PMAS_DB_PASS|PMAS_DATA_KEY|GEMINI_API_KEY|OPENAI_API_KEY)\s*=\s*[^\s]+'
$leakResults = Get-ChildItem $SiteRoot -Recurse -File | Where-Object Extension -In '.js','.html','.css' | Select-String -Pattern $secretPattern -Quiet
if ($leakResults -contains $true) { throw 'A secret-like environment assignment was found in a browser asset.' }

$uploadFiles = Get-ChildItem (Join-Path $SiteRoot 'assets/uploads') -Recurse -File -ErrorAction SilentlyContinue
if ($uploadFiles) { throw 'Runtime upload files must not be included in the production code bundle.' }

Write-Host 'Production bundle readiness checks passed.'

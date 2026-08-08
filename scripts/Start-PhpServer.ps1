param(
    [int]$Port = 8080,
    [string]$HostAddress = '127.0.0.1'
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpCommand = Get-Command php -ErrorAction SilentlyContinue

if ($null -eq $phpCommand) {
    $xamppPhp = 'C:\xampp\php\php.exe'
    if (Test-Path -LiteralPath $xamppPhp) {
        $phpExecutable = $xamppPhp
    } else {
        throw 'PHP was not found. Install PHP 8.2+ or add php.exe to PATH.'
    }
} else {
    $phpExecutable = $phpCommand.Source
}

$env:PMAS_APP_URL = "http://${HostAddress}:$Port"
$env:PMAS_BASE_PATH = ''
Set-Location $projectRoot
Write-Host "PMAS PHP backend: http://${HostAddress}:$Port"
& $phpExecutable -S "${HostAddress}:$Port" -t $projectRoot (Join-Path $projectRoot 'router.php')

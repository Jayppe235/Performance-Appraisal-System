param(
    [int]$BackendPort = 8080,
    [int]$FrontendPort = 5173
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backendScript = Join-Path $PSScriptRoot 'Start-PhpServer.ps1'
$backend = Start-Process powershell -ArgumentList @(
    '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $backendScript,
    '-Port', $BackendPort
) -WindowStyle Hidden -PassThru

try {
    $env:VITE_DEV_PHP_ORIGIN = "http://127.0.0.1:$BackendPort"
    $env:VITE_DEV_PHP_BASE_PATH = '/'
    $env:VITE_DEV_HTTPS = 'false'
    Set-Location $projectRoot
    Write-Host "PMAS frontend: http://localhost:$FrontendPort"
    Write-Host 'Press Ctrl+C to stop both servers.'
    $npmCommand = Get-Command npm.cmd -ErrorAction SilentlyContinue
    if ($null -eq $npmCommand) {
        throw 'npm.cmd was not found. Install Node.js or add it to PATH.'
    }
    & $npmCommand.Source run dev -- --port $FrontendPort
} finally {
    if (!$backend.HasExited) {
        Stop-Process -Id $backend.Id
    }
}

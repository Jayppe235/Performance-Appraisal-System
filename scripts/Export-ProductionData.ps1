param(
    [string]$Database = 'pmas_db_clean',
    [string]$HostName = '127.0.0.1',
    [int]$Port = 3306,
    [string]$User = 'root',
    [switch]$NoPassword,
    [string]$Output
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $Output) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $Output = "private-backups/pmas-production-$stamp.sql"
}
$target = Join-Path $root $Output
$targetDirectory = Split-Path -Parent $target
New-Item -ItemType Directory -Force -Path $targetDirectory | Out-Null

$dump = Get-Command mysqldump -ErrorAction SilentlyContinue
if (-not $dump) {
    $xamppDump = 'C:\xampp\mysql\bin\mysqldump.exe'
    if (Test-Path -LiteralPath $xamppDump) { $dump = Get-Item -LiteralPath $xamppDump }
}
if (-not $dump) { throw 'mysqldump was not found. Install MySQL tools or use the XAMPP copy.' }
$dumpExecutable = if ($dump.Source) { $dump.Source } else { $dump.FullName }

Write-Warning 'This export contains real database records. Keep it outside web hosting and Git.'
$arguments = @("--host=$HostName", "--port=$Port", "--user=$User")
if (-not $NoPassword) { $arguments += '--password' }
$arguments += @(
    '--single-transaction', '--routines', '--triggers', '--events',
    '--hex-blob', '--default-character-set=utf8mb4', '--add-drop-table', $Database
)

& $dumpExecutable @arguments | Set-Content -Encoding utf8 -LiteralPath $target
if ($LASTEXITCODE -ne 0) {
    Remove-Item -LiteralPath $target -Force -ErrorAction SilentlyContinue
    throw "mysqldump failed with exit code $LASTEXITCODE"
}
if ((Get-Item -LiteralPath $target).Length -eq 0) {
    Remove-Item -LiteralPath $target -Force
    throw 'mysqldump created an empty export.'
}

Write-Host "Private production export created: $target"
Write-Host 'Stop application writes before creating the final cutover export.'

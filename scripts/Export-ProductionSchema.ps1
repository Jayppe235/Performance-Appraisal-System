param(
    [string]$Database = 'pmas_db_clean',
    [string]$HostName = '127.0.0.1',
    [int]$Port = 3306,
    [string]$User = 'root',
    [switch]$NoPassword,
    [string]$Output = 'release/production-schema.sql'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$target = Join-Path $root $Output
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null

$dump = Get-Command mysqldump -ErrorAction SilentlyContinue
if (-not $dump) {
    $xamppDump = 'C:\xampp\mysql\bin\mysqldump.exe'
    if (Test-Path $xamppDump) { $dump = Get-Item $xamppDump }
}
if (-not $dump) { throw 'mysqldump was not found. Install MySQL tools or use the XAMPP copy.' }
$dumpExecutable = if ($dump.Source) { $dump.Source } else { $dump.FullName }

Write-Host 'Exporting structure only. No users, passwords, evaluations, or test data will be included.'
$arguments = @("--host=$HostName", "--port=$Port", "--user=$User")
if (-not $NoPassword) { $arguments += '--password' }
$arguments += @('--no-data', '--routines', '--triggers', '--events', '--single-transaction', '--skip-add-drop-database', '--no-create-db', '--default-character-set=utf8mb4', $Database)
& $dumpExecutable @arguments | Set-Content -Encoding utf8 $target
if ($LASTEXITCODE -ne 0) { throw "mysqldump failed with exit code $LASTEXITCODE" }
Write-Host "Production schema created: $target"

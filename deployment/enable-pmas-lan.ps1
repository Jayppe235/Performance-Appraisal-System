# Run this script as Administrator.
$ErrorActionPreference = 'Stop'

Set-NetConnectionProfile -InterfaceAlias 'Wi-Fi' -NetworkCategory Private

$rules = @(
    @{ Name = 'PMAS HTTP LAN'; Port = 80 },
    @{ Name = 'PMAS HTTPS LAN'; Port = 443 }
)

foreach ($rule in $rules) {
    $existing = Get-NetFirewallRule -DisplayName $rule.Name -ErrorAction SilentlyContinue
    if ($existing) {
        $existing | Set-NetFirewallRule -Enabled True -Profile Private -Direction Inbound -Action Allow
        $existing | Get-NetFirewallPortFilter | Set-NetFirewallPortFilter -Protocol TCP -LocalPort $rule.Port
        $existing | Get-NetFirewallAddressFilter | Set-NetFirewallAddressFilter -RemoteAddress '192.168.1.0/24'
    } else {
        New-NetFirewallRule `
            -DisplayName $rule.Name `
            -Direction Inbound `
            -Action Allow `
            -Protocol TCP `
            -LocalPort $rule.Port `
            -RemoteAddress '192.168.1.0/24' `
            -Profile Private | Out-Null
    }
}

Write-Host 'PMAS LAN access enabled for http://192.168.1.3/PMAS/' -ForegroundColor Green
Write-Host 'You may close this window.'

#Requires -Version 5.1
<#
.SYNOPSIS
  Apply the same Cloudflare rules used on bedda.ca to onlybikes.shop via API.

.DESCRIPTION
  Creates cache rules, WAF custom rules, and zone security settings matching
  CLOUDFLARE-ONLYBIKES.md. Requires a Cloudflare API token with:
    - Zone Settings Edit
    - Zone WAF Edit / Cache Rules Edit (or Account Rulesets Edit)

.PARAMETER ZoneName
  Domain zone name (default: onlybikes.shop)

.PARAMETER ApiToken
  Cloudflare API token. Falls back to $env:CLOUDFLARE_API_TOKEN.

.PARAMETER ZoneId
  Optional zone ID. If omitted, looked up from ZoneName.

.EXAMPLE
  $env:CLOUDFLARE_API_TOKEN = "your-token"
  .\scripts\apply-cloudflare-onlybikes.ps1

.EXAMPLE
  .\scripts\apply-cloudflare-onlybikes.ps1 -ApiToken "your-token" -WhatIf
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$ZoneName = "onlybikes.shop",
    [string]$ApiToken = $env:CLOUDFLARE_API_TOKEN,
    [string]$ZoneId = $env:CLOUDFLARE_ZONE_ID
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$BaseUrl = "https://api.cloudflare.com/client/v4"

function Invoke-CfApi {
    param(
        [ValidateSet("GET", "POST", "PUT", "PATCH")]
        [string]$Method,
        [string]$Path,
        [object]$Body = $null
    )

    $headers = @{
        Authorization = "Bearer $ApiToken"
        "Content-Type" = "application/json"
    }

    $uri = "$BaseUrl$Path"
    $json = if ($null -ne $Body) { $Body | ConvertTo-Json -Depth 12 -Compress } else { $null }

    if ($PSCmdlet.ShouldProcess($uri, $Method)) {
        if ($json) {
            return Invoke-RestMethod -Method $Method -Uri $uri -Headers $headers -Body $json
        }
        return Invoke-RestMethod -Method $Method -Uri $uri -Headers $headers
    }

    Write-Host "[WhatIf] $Method $uri"
    if ($json) { Write-Host $json }
    return @{ success = $true; result = $null }
}

function Get-PhaseRuleset {
    param([string]$Phase)
    try {
        $resp = Invoke-CfApi -Method GET -Path "/zones/$ZoneId/rulesets/phases/$Phase/entrypoint"
        if ($resp.success) { return $resp.result }
    } catch {
        if ($_.Exception.Response.StatusCode.value__ -ne 404) { throw }
    }
    return $null
}

function Set-PhaseRules {
    param(
        [string]$Phase,
        [string]$Name,
        [array]$Rules
    )

    $existing = Get-PhaseRuleset -Phase $Phase
    if ($existing -and $existing.id) {
        Write-Host "Updating $Phase ruleset ($($existing.id))..."
        return Invoke-CfApi -Method PUT -Path "/zones/$ZoneId/rulesets/$($existing.id)" -Body @{
            rules = $Rules
        }
    }

    Write-Host "Creating $Phase ruleset..."
    return Invoke-CfApi -Method POST -Path "/zones/$ZoneId/rulesets" -Body @{
        name = $Name
        kind = "zone"
        phase = $Phase
        rules = $Rules
    }
}

function Set-ZoneSetting {
    param([string]$SettingId, [string]$Value)
    Write-Host "Setting $SettingId = $Value"
    Invoke-CfApi -Method PATCH -Path "/zones/$ZoneId/settings/$SettingId" -Body @{
        value = $Value
    } | Out-Null
}

if (-not $ApiToken) {
    throw @"
CLOUDFLARE_API_TOKEN is required.

Create a token at https://dash.cloudflare.com/profile/api-tokens
Template: 'Edit zone DNS' + add permissions:
  - Zone > Zone Settings > Edit
  - Zone > Cache Rules > Edit
  - Zone > Firewall Services > Edit

Then run:
  `$env:CLOUDFLARE_API_TOKEN = 'your-token'
  .\scripts\apply-cloudflare-onlybikes.ps1
"@
}

if (-not $ZoneId) {
    Write-Host "Looking up zone ID for $ZoneName..."
    $zones = Invoke-CfApi -Method GET -Path "/zones?name=$ZoneName"
    if (-not $zones.success -or -not $zones.result.Count) {
        throw "Zone '$ZoneName' not found in your Cloudflare account. Add the site first."
    }
    $ZoneId = $zones.result[0].id
}
Write-Host "Zone: $ZoneName ($ZoneId)"

# --- Part A: Cache Rules ---
$cacheBypassExpr = '(http.request.uri.path starts_with "/api/") or (http.request.uri.path starts_with "/email-admin/")'
$cacheStaticExpr = '(http.request.uri.path ends_with ".jpg") or (http.request.uri.path ends_with ".png") or (http.request.uri.path ends_with ".webp") or (http.request.uri.path ends_with ".css") or (http.request.uri.path ends_with ".js") or (http.request.uri.path ends_with ".woff2") or (http.request.uri.path ends_with ".svg") or (http.request.uri.path ends_with ".mp4")'

$cacheRules = @(
    @{
        description = "Bypass API and admin"
        expression = $cacheBypassExpr
        action = "set_cache_settings"
        action_parameters = @{ cache = $false }
        enabled = $true
    },
    @{
        description = "Cache static assets"
        expression = $cacheStaticExpr
        action = "set_cache_settings"
        action_parameters = @{
            cache = $true
            edge_ttl = @{
                mode = "override_origin"
                default = 2592000
            }
        }
        enabled = $true
    }
)

Set-PhaseRules -Phase "http_request_cache_settings" -Name "OnlyBikes cache rules" -Rules $cacheRules | Out-Null
Write-Host "Cache rules applied."

# --- Part B: WAF Custom Rules ---
$wafRules = @(
    @{
        description = "Allow Stripe webhooks"
        expression = '(http.request.uri.path eq "/api/stripe-webhook.php")'
        action = "skip"
        action_parameters = @{
            ruleset = "current"
            phases = @("http_ratelimit", "http_request_firewall_managed", "http_request_sbfm")
        }
        enabled = $true
    },
    @{
        description = "Allow customer auth API"
        expression = '(http.request.uri.path eq "/api/customer-auth.php" and http.request.method eq "POST")'
        action = "skip"
        action_parameters = @{
            phases = @("http_ratelimit", "http_request_firewall_managed")
        }
        enabled = $true
    },
    @{
        description = "Protect email admin"
        expression = '(http.request.uri.path contains "/email-admin/")'
        action = "managed_challenge"
        enabled = $true
    },
    @{
        description = "Block sensitive paths"
        expression = '(http.request.uri.path contains "/.env") or (http.request.uri.path contains "/.git") or (http.request.uri.path contains "/secure-config")'
        action = "block"
        enabled = $true
    },
    @{
        description = "Rate limit checkout"
        expression = '(http.request.uri.path eq "/api/submit-order.php" and http.request.method eq "POST")'
        action = "block"
        ratelimit = @{
            characteristics = @("ip.src")
            period = 60
            requests_per_period = 10
            mitigation_timeout = 600
        }
        enabled = $true
    }
)

Set-PhaseRules -Phase "http_request_firewall_custom" -Name "OnlyBikes WAF custom rules" -Rules $wafRules | Out-Null
Write-Host "WAF custom rules applied."

# --- Part C: Security toggles ---
Set-ZoneSetting -SettingId "ssl" -Value "strict"
Set-ZoneSetting -SettingId "always_use_https" -Value "on"
Set-ZoneSetting -SettingId "security_level" -Value "medium"
Set-ZoneSetting -SettingId "browser_check" -Value "on"

try {
    Invoke-CfApi -Method PUT -Path "/zones/$ZoneId/bot_management" -Body @{
        fight_mode = $false
    } | Out-Null
    Write-Host "Bot Fight Mode left OFF (required for customer login POST)."
} catch {
    Write-Warning "Confirm Bot Fight Mode is OFF manually: Security -> Bots"
}

Write-Host ""
Write-Host "Done. Verify:"
Write-Host "  https://onlybikes.shop/"
Write-Host "  https://onlybikes.shop/api/health.php"
Write-Host "  https://onlybikes.shop/email-admin/"
Write-Host ""
Write-Host "DNS: confirm @ and www are Proxied (orange cloud). Leave MX/TXT grey."

<#!
.SYNOPSIS
  Start ngrok tunnel for Saku Santri project (PowerShell).

.USAGE
  pwsh.exe -ExecutionPolicy Bypass -File .\start_ngrok.ps1
  (Pastikan ngrok.exe ada di PATH atau folder yang sama.)
#>

param(
  [int]$Port = 80,
  [switch]$Once
)

$ErrorActionPreference = 'Stop'

Write-Host "[ngrok] Checking configuration..." -ForegroundColor Cyan

$cfgExample = Join-Path $PSScriptRoot 'ngrok.example.yml'
$cfgReal    = Join-Path $PSScriptRoot 'ngrok.yml'

if (-not (Test-Path $cfgReal)) {
  Copy-Item $cfgExample $cfgReal
  Write-Warning "Created ngrok.yml from template. Edit authtoken before continuing (file: ngrok.yml)."
  Write-Host "Open ngrok.yml now? (Y/N)" -NoNewline
  $answer = Read-Host
  if ($answer -match '^(y|ya)') { Start-Process $cfgReal }
}

if (-not (Get-Command ngrok -ErrorAction SilentlyContinue)) {
  Write-Error "ngrok executable not found in PATH. Download: https://ngrok.com/download"; exit 1
}

# Optional dynamic port override
if ($Port -ne 80) {
  (Get-Content $cfgReal) | ForEach-Object {
    if ($_ -match '^\s*addr:\s*80') { '    addr: ' + $Port } else { $_ }
  } | Set-Content $cfgReal
  Write-Host "[ngrok] Adjusted tunnel addr to port $Port" -ForegroundColor Yellow
}

Write-Host "[ngrok] Starting tunnel..." -ForegroundColor Green
# Pre-flight local health check
try {
  $healthUrl = "http://localhost:$Port/saku_santri/health.php"
  Write-Host "[ngrok] Checking local app: $healthUrl" -ForegroundColor Cyan
  $resp = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 5
  if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 300) {
    Write-Host "[ngrok] Local health OK ($($resp.StatusCode))" -ForegroundColor Green
  } else {
    Write-Warning "Local health endpoint returned status $($resp.StatusCode). Continuing anyway..."
  }
} catch {
  Write-Warning "Gagal mengakses health endpoint. Pastikan Apache jalan & URL benar. ($_ )"
}

if ($Once) {
  ngrok start sakusantri --config "$cfgReal"
} else {
  ngrok start --all --config "$cfgReal"
}

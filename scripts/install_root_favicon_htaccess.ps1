# Creates or updates C:\xampp\htdocs\.htaccess to point root-level favicon requests to the project's logo
$root = Split-Path -Path $PSScriptRoot -Parent
$target = Join-Path -Path $root -ChildPath ".htaccess"
$content = @"
RewriteEngine On
# Force root-level favicon requests to use project brand icon
RewriteRule ^favicon\.ico$ /sakusantri/public/assets/img/logo.png [L]
RewriteRule ^favicon\.png$ /sakusantri/public/assets/img/logo.png [L]
RewriteRule ^apple-touch-icon(.*)\.png$ /sakusantri/public/assets/img/logo.png [L]
RewriteRule ^android-chrome-(.*)\.png$ /sakusantri/public/assets/img/logo.png [L]
"@

# Backup existing .htaccess if present
if (Test-Path $target) {
  $backup = Join-Path -Path $root -ChildPath (".htaccess.bak_" + (Get-Date -Format "yyyyMMdd_HHmmss"))
  Copy-Item -Path $target -Destination $backup -Force
}

Set-Content -Path $target -Value $content -Encoding UTF8
Write-Host "Root .htaccess updated: $target"
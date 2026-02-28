# Start preview for the game
# Run from PowerShell. Then open: http://localhost:8080
# Uses Python if available; otherwise npx serve

$port = 8080
Set-Location $PSScriptRoot

Write-Host "Serving game at http://localhost:$port" -ForegroundColor Green
Write-Host "Press Ctrl+C to stop." -ForegroundColor Gray
Write-Host ""

try {
  python -m http.server $port
} catch {
  Write-Host "Python not found, using npx serve..." -ForegroundColor Yellow
  npx -y serve -l $port
}

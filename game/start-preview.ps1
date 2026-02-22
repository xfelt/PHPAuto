# Start preview for the game
# Run from PowerShell. Then open: http://localhost:8080

$port = 8080
Set-Location $PSScriptRoot

Write-Host "Serving game at http://localhost:$port" -ForegroundColor Green
Write-Host "Press Ctrl+C to stop." -ForegroundColor Gray
Write-Host ""

python -m http.server $port

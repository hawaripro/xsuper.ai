$names = @(
    'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_CONFIG_CACHE',
    'APP_MAINTENANCE_DRIVER', 'APP_MAINTENANCE_STORE',
    'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
    'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_COLLATION', 'DB_SSLMODE',
    'DB_QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION',
    'BROADCAST_CONNECTION'
)
foreach ($name in $names) {
    Remove-Item "Env:$name" -ErrorAction SilentlyContinue
}
$previousScanDir = [Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR', 'Process')
$mediaIniDir = Join-Path $PSScriptRoot 'php'
$separator = [IO.Path]::PathSeparator
$phpArguments = @($args)
# Preserve scanned INI settings in artisan's child server; restart after .env changes.
if ($phpArguments.Count -ge 2 -and $phpArguments[0] -eq 'artisan' -and $phpArguments[1] -eq 'serve' -and $phpArguments -notcontains '--no-reload') {
    $phpArguments += '--no-reload'
}
try {
    [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', "$previousScanDir$separator$mediaIniDir", 'Process')
    & php @phpArguments
    $exitCode = $LASTEXITCODE
}
finally {
    [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', $previousScanDir, 'Process')
}
exit $exitCode

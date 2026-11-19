param([switch]$RestartStream, [switch]$RestartQueues)

$ErrorActionPreference = 'Stop'
$projectDirectory = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$phpExecutable = (Get-Command php.exe -ErrorAction Stop).Source
$opensslConfig = Join-Path (Split-Path $phpExecutable) 'extras\ssl\openssl.cnf'
if (-not $env:OPENSSL_CONF -and (Test-Path -LiteralPath $opensslConfig)) {
    $env:OPENSSL_CONF = $opensslConfig
}
$logDirectory = Join-Path $projectDirectory 'storage\logs'
$artisanPath = Join-Path $projectDirectory 'artisan'
$existing = @(Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object {
    $_.CommandLine -and $_.CommandLine.Contains($artisanPath)
})
foreach ($worker in @(
    @{ Name = 'notification-queue'; Match = '--queue=notifications,default'; Args = @('queue:work', 'database', '--queue=notifications,default', '--sleep=2', '--tries=5', '--timeout=60') },
    @{ Name = 'webpush-queue'; Match = '--queue=webpush'; Args = @('queue:work', 'database', '--queue=webpush', '--sleep=2', '--tries=5', '--timeout=30') },
    @{ Name = 'notification-stream'; Match = 'notifications:serve'; Args = @('notifications:serve') }
)) {
    $matches = @($existing | Where-Object { $_.CommandLine.Contains($worker.Match) })
    if ($matches.Count -gt 0) {
        if (($RestartStream -and $worker.Match -eq 'notifications:serve') -or ($RestartQueues -and $worker.Match -like '--queue=*')) {
            foreach ($match in $matches) {
                $running = Get-Process -Id $match.ProcessId -ErrorAction SilentlyContinue
                if ($running -and $running.Path -eq $phpExecutable) {
                    Stop-Process -InputObject $running -Force
                }
            }
        } else {
            Write-Output ($worker.Name + ' is already running for this project.')
            continue
        }
    }
    $arguments = @('"' + $artisanPath + '"') + $worker.Args
    $process = Start-Process -WindowStyle Hidden -FilePath $phpExecutable -ArgumentList $arguments -WorkingDirectory $projectDirectory -RedirectStandardOutput (Join-Path $logDirectory ($worker.Name + '.log')) -RedirectStandardError (Join-Path $logDirectory ($worker.Name + '.err.log')) -PassThru
    Write-Output ($worker.Name + ' started, PID ' + $process.Id)
}

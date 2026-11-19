$ErrorActionPreference = 'Stop'
$apacheDirectory = 'C:\laragon\bin\apache\httpd-2.4.62-240904-win64-VS17'
$configPath = Join-Path $apacheDirectory 'conf\httpd.conf'
$sitePath = 'C:\laragon\etc\apache2\sites-enabled\auto.path-v3.test.conf'
$apacheExe = Join-Path $apacheDirectory 'bin\httpd.exe'
$configuration = [IO.File]::ReadAllText($configPath)
$site = [IO.File]::ReadAllText($sitePath)
if (-not $site.Contains('DocumentRoot "C:/laragon/www/path-v3/public"')) {
    throw 'The expected PATH virtual host was not found; no configuration changed.'
}
foreach ($module in @('proxy', 'proxy_http')) {
    $pattern = '(?m)^#?LoadModule ' + $module + '_module modules/mod_' + $module + '\.so\r?$'
    if ($configuration -notmatch $pattern) { throw "Cannot locate bundled $module module." }
    $configuration = [regex]::Replace($configuration, $pattern, "LoadModule ${module}_module modules/mod_${module}.so")
}
if (-not $site.Contains('# PATH notification stream')) {
    $directives = @'
    # PATH notification stream: handled by the dedicated PHP process.
    ProxyPass "/notification-events" "http://127.0.0.1:8091/notification-events" connectiontimeout=5 timeout=330
    ProxyPassReverse "/notification-events" "http://127.0.0.1:8091/notification-events"
    SetEnvIf Request_URI "^/notification-events$" no-gzip=1
    # Do not put signed ticket query strings in the access log.
    CustomLog "C:/laragon/www/path-v3/storage/logs/apache-access.log" "%h %l %u %t \"%m %U %H\" %>s %b"
'@
    $site = $site.Replace('</VirtualHost>', $directives + "`r`n</VirtualHost>")
}
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
Copy-Item -LiteralPath $configPath -Destination ($configPath + '.notifications-' + $stamp + '.bak')
Copy-Item -LiteralPath $sitePath -Destination ($sitePath + '.notifications-' + $stamp + '.bak')
$utf8 = New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText($configPath, $configuration, $utf8)
[IO.File]::WriteAllText($sitePath, $site, $utf8)
& $apacheExe -t
if ($LASTEXITCODE -ne 0) {
    Copy-Item -LiteralPath ($configPath + '.notifications-' + $stamp + '.bak') -Destination $configPath -Force
    Copy-Item -LiteralPath ($sitePath + '.notifications-' + $stamp + '.bak') -Destination $sitePath -Force
    throw 'Apache configuration validation failed; both original files restored.'
}
Write-Output 'Apache configuration validated. Backups are next to the two configuration files.'
if (Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue) {
    & $apacheExe -k restart
    if ($LASTEXITCODE -ne 0) { throw 'Apache service reload failed.' }
} else {
    # Laragon launches this installation directly, without a Windows service.
    $apacheProcesses = @(Get-CimInstance Win32_Process -Filter "Name = 'httpd.exe'" | Where-Object {
        $_.ExecutablePath -eq $apacheExe
    })
    $apacheIds = @($apacheProcesses | ForEach-Object { $_.ProcessId })
    $parents = @($apacheProcesses | Where-Object { $_.ParentProcessId -notin $apacheIds })
    if ($parents.Count -ne 1) { throw 'Expected one Laragon Apache parent. Reload through Laragon instead.' }
    $expectedCommand = Join-Path $apacheDirectory 'bin\httpd'
    if ($parents[0].CommandLine.Trim().Trim('"') -notin @($expectedCommand, $apacheExe)) {
        throw 'Apache uses custom launch arguments. Reload through Laragon instead.'
    }
    foreach ($apacheProcess in $apacheProcesses) {
        $running = Get-Process -Id $apacheProcess.ProcessId -ErrorAction SilentlyContinue
        if ($running -and $running.Path -eq $apacheExe) { Stop-Process -InputObject $running -Force }
    }
    Start-Sleep -Milliseconds 500
    $started = Start-Process -WindowStyle Hidden -FilePath $apacheExe -WorkingDirectory $apacheDirectory -PassThru
    Write-Output ('Laragon Apache restarted, PID ' + $started.Id)
}

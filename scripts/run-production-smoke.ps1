Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$composeFile = Join-Path $repoRoot 'docker-compose.production.yml'
$runToken = ([guid]::NewGuid().ToString('N')).Substring(0, 12)
$composeProject = "myshop-production-smoke-$runToken"
$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) "myshop-production-smoke-$runToken"
$envFile = Join-Path $tempRoot 'compose.env'
$overrideFile = Join-Path $tempRoot 'smoke.override.yml'
$httpBodyFile = $null
$curlCommand = $null
$composeStarted = $false
$exitCode = 1

function Get-FreeLoopbackPort {
    $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    try {
        $listener.Start()
        return $listener.LocalEndpoint.Port
    } finally {
        $listener.Stop()
    }
}

function Get-ComposeArguments {
    return @(
        '--project-name', $composeProject,
        '--env-file', $envFile,
        '--file', $composeFile,
        '--file', $overrideFile
    )
}

function Invoke-Compose {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$CommandArguments,
        [switch]$AllowFailure
    )

    $composeArguments = (Get-ComposeArguments) + $CommandArguments
    & docker compose @composeArguments | Out-Null
    $commandExitCode = $LASTEXITCODE
    if (-not $AllowFailure -and $commandExitCode -ne 0) {
        throw 'Docker Compose command failed.'
    }
    return $commandExitCode
}

function Get-ComposeContainerId {
    param([Parameter(Mandatory = $true)][string]$Service)

    $composeArguments = Get-ComposeArguments
    $containerId = (& docker compose @composeArguments ps --quiet $Service).Trim()
    if ($LASTEXITCODE -ne 0 -or $containerId -notmatch '^[0-9a-f]{12,64}$') {
        throw 'The disposable production container could not be identified.'
    }
    return $containerId
}

function Get-HttpResult {
    param([Parameter(Mandatory = $true)][string]$Url)

    if ($null -eq $script:httpBodyFile -or $null -eq $script:curlCommand) {
        return [pscustomobject]@{ Status = 0; Body = '' }
    }

    $statusText = & $script:curlCommand --silent --show-error --max-time 5 --request GET --output $script:httpBodyFile --write-out '%{http_code}' $Url 2>$null
    if ($LASTEXITCODE -ne 0 -or ($statusText -join '').Trim() -notmatch '^\d{3}$') {
        return [pscustomobject]@{ Status = 0; Body = '' }
    }

    $body = ''
    if (Test-Path -LiteralPath $script:httpBodyFile) {
        $body = [string](Get-Content -LiteralPath $script:httpBodyFile -Raw)
    }

    return [pscustomobject]@{
        Status = [int](($statusText -join '').Trim())
        Body = $body
    }
}

function Wait-ForHttpResponse {
    param(
        [Parameter(Mandatory = $true)][string]$Url,
        [Parameter(Mandatory = $true)][int]$ExpectedStatus,
        [Parameter(Mandatory = $true)][string]$ExpectedBody,
        [int]$Attempts = 60
    )

    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        $result = Get-HttpResult -Url $Url
        if ($result.Status -eq $ExpectedStatus -and $result.Body -eq $ExpectedBody) {
            return
        }
        Start-Sleep -Seconds 2
    }

    throw 'The disposable production endpoint did not reach its expected response.'
}

function Assert-ProductionInspection {
    param(
        [Parameter(Mandatory = $true)]$AppInspection,
        [Parameter(Mandatory = $true)]$DatabaseInspection
    )

    if (-not [bool]$AppInspection.HostConfig.ReadonlyRootfs) {
        throw 'The production application root filesystem is not read-only.'
    }

    $volumeMounts = @($AppInspection.Mounts | Where-Object { $_.Type -eq 'volume' })
    if ($volumeMounts.Count -ne 1) {
        throw 'The production application writable volume boundary is invalid.'
    }
    if ($volumeMounts[0].Destination -ne '/var/www/html/public/uploads' -or -not [bool]$volumeMounts[0].RW) {
        throw 'The production application writable volume boundary is invalid.'
    }

    $bindMounts = @($AppInspection.Mounts | Where-Object { $_.Type -eq 'bind' })
    if ($bindMounts.Count -ne 0) {
        throw 'The production application must not use a host bind mount.'
    }

    $securityOptions = @($AppInspection.HostConfig.SecurityOpt)
    if ($securityOptions -notcontains 'no-new-privileges:true') {
        throw 'The production application does not enable no-new-privileges.'
    }

    $portBindingProperties = @(
        if ($null -ne $DatabaseInspection.HostConfig.PortBindings) {
            $DatabaseInspection.HostConfig.PortBindings.PSObject.Properties
        }
    )
    if ($portBindingProperties.Count -ne 0) {
        throw 'The disposable production database publishes a host port.'
    }

    $appEnvironment = @($AppInspection.Config.Env)
    foreach ($forbiddenName in @('MYSQL_ROOT_PASSWORD', 'DB_SCHEMA_USER', 'DB_SCHEMA_PASSWORD', 'TEST_DB_ROOT_PASSWORD')) {
        if ($appEnvironment | Where-Object { $_ -like "$forbiddenName=*" }) {
            throw 'The production application received a forbidden database credential setting.'
        }
    }
}

try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Docker is required for the production runtime smoke test.'
    }

    New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
    $curlCommand = if (Get-Command curl.exe -ErrorAction SilentlyContinue) { 'curl.exe' } else { 'curl' }
    if (-not (Get-Command $curlCommand -ErrorAction SilentlyContinue)) {
        throw 'curl is required for the production runtime smoke test.'
    }
    $httpBodyFile = Join-Path $tempRoot 'http-body.txt'
    $appPort = Get-FreeLoopbackPort
    $databaseName = "myshop_production_smoke_$runToken"
    $databaseUser = "myshop_smoke_$runToken"
    $databasePassword = ([guid]::NewGuid().ToString('N')) + ([guid]::NewGuid().ToString('N'))
    $schemaPassword = ([guid]::NewGuid().ToString('N')) + ([guid]::NewGuid().ToString('N'))
    $rootPassword = ([guid]::NewGuid().ToString('N')) + ([guid]::NewGuid().ToString('N'))
    $appImage = "myshop-production-smoke-${runToken}:ci"

    @(
        'APP_ENV=production',
        "DB_HOST=db",
        'DB_PORT=3306',
        "DB_NAME=$databaseName",
        "DB_USER=$databaseUser",
        "DB_PASSWORD=$databasePassword",
        "DB_SCHEMA_USER=myshop_schema_$runToken",
        "DB_SCHEMA_PASSWORD=$schemaPassword",
        "MYSQL_ROOT_PASSWORD=$rootPassword",
        'PHP_BASE_IMAGE=php:8.3-apache-bookworm@sha256:69e641a40929c9db9d1ce43b982d46f6701ccdd5f7b4c32d5bd773cae6882393',
        "PRODUCTION_APP_IMAGE=$appImage",
        'PRODUCTION_MYSQL_IMAGE=mysql:8.4.3@sha256:106d5197fd8e4892980469ad42eb20f7a336bd81509aae4ee175d852f5cc4565',
        'TRUSTED_PROXY_IPS=127.0.0.1',
        'HSTS_ENABLED=true',
        'HSTS_MAX_AGE=31536000'
    ) | Set-Content -LiteralPath $envFile -Encoding ascii

    @"
services:
  app:
    ports:
      - "127.0.0.1:${appPort}:80"
"@ | Set-Content -LiteralPath $overrideFile -Encoding ascii

    Write-Host 'Starting disposable production runtime smoke environment.'
    $composeStarted = $true
    Invoke-Compose -CommandArguments @('config', '--quiet') | Out-Null
    Invoke-Compose -CommandArguments @('build', '--pull', 'app') | Out-Null
    Invoke-Compose -CommandArguments @('up', '--detach', 'db', 'app') | Out-Null

    $baseUrl = "http://127.0.0.1:$appPort"
    Wait-ForHttpResponse -Url "$baseUrl/health.php" -ExpectedStatus 200 -ExpectedBody '{"status":"ok","check":"liveness"}'
    Wait-ForHttpResponse -Url "$baseUrl/ready.php" -ExpectedStatus 200 -ExpectedBody '{"status":"ready","check":"database"}'

    $appContainerId = Get-ComposeContainerId -Service 'app'
    $databaseContainerId = Get-ComposeContainerId -Service 'db'
    $appInspection = @(docker inspect $appContainerId | ConvertFrom-Json)[0]
    $databaseInspection = @(docker inspect $databaseContainerId | ConvertFrom-Json)[0]
    Assert-ProductionInspection -AppInspection $appInspection -DatabaseInspection $databaseInspection

    $gitCheckExitCode = Invoke-Compose -CommandArguments @(
        'run', '--rm', '--no-deps', 'app', 'sh', '-c',
        'if command -v git >/dev/null 2>&1; then exit 41; fi; if test -e /var/www/html/.git; then exit 42; fi'
    ) -AllowFailure
    if ($gitCheckExitCode -ne 0) {
        throw 'The production image contains Git or repository metadata.'
    }

    $phpDisplayCheckExitCode = Invoke-Compose -CommandArguments @(
        'exec', '-T', 'app', 'php', '-r',
        "if (filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || filter_var(ini_get('display_startup_errors'), FILTER_VALIDATE_BOOLEAN)) { exit(1); }"
    ) -AllowFailure
    if ($phpDisplayCheckExitCode -ne 0) {
        throw 'Production PHP error display is enabled.'
    }

    Invoke-Compose -CommandArguments @('stop', 'db') | Out-Null
    Wait-ForHttpResponse -Url "$baseUrl/health.php" -ExpectedStatus 200 -ExpectedBody '{"status":"ok","check":"liveness"}' -Attempts 30
    Wait-ForHttpResponse -Url "$baseUrl/ready.php" -ExpectedStatus 503 -ExpectedBody '{"status":"not_ready","check":"database"}' -Attempts 30

    Invoke-Compose -CommandArguments @('start', 'db') | Out-Null
    Wait-ForHttpResponse -Url "$baseUrl/ready.php" -ExpectedStatus 200 -ExpectedBody '{"status":"ready","check":"database"}' -Attempts 60

    $composeArguments = Get-ComposeArguments
    $applicationLogs = (& docker compose @composeArguments logs --no-color --no-log-prefix app) -join "`n"
    if ($applicationLogs -match '(?im)PHP\s+(?:Fatal error|Parse error)|Uncaught\s+(?:Error|Exception)|Stack trace:') {
        throw 'The production application emitted an unexpected fatal runtime error.'
    }

    Write-Host 'PASS: disposable production runtime smoke and isolation checks passed.'
    $exitCode = 0
} catch {
    Write-Error 'Disposable production runtime smoke failed.'
    $exitCode = 1
} finally {
    $cleanupFailed = $false
    if ($composeStarted) {
        try {
            $downExitCode = Invoke-Compose -CommandArguments @('down', '--rmi', 'local', '--volumes', '--remove-orphans') -AllowFailure
            if ($downExitCode -ne 0) {
                $cleanupFailed = $true
            }
        } catch {
            $cleanupFailed = $true
        }
    }

    if (Test-Path -LiteralPath $tempRoot) {
        try {
            Remove-Item -LiteralPath $tempRoot -Recurse -Force -ErrorAction Stop
        } catch {
            $cleanupFailed = $true
        }
    }

    if ($cleanupFailed) {
        Write-Error 'Disposable production runtime cleanup failed.'
        $exitCode = 1
    }
}

exit $exitCode

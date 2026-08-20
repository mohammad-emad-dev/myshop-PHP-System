Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$e2eRoot = Join-Path $repoRoot 'e2e'
$runToken = ([guid]::NewGuid().ToString('N')).Substring(0, 12)
$composeProject = "myshop-browser-qa-$runToken"
$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) "myshop-browser-qa-$runToken"
$envFile = Join-Path $tempRoot 'compose.env'
$seedEnvFile = Join-Path $tempRoot 'seed.env'
$outputDirectory = Join-Path $tempRoot 'playwright-output'
$appPort = $null
$mysqlPort = $null
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

function Invoke-Compose {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$CommandArguments,
        [switch]$AllowFailure
    )

    $composeArguments = @(
        '--project-name', $composeProject,
        '--env-file', $envFile,
        '--file', (Join-Path $repoRoot 'docker-compose.yml'),
        '--file', (Join-Path $e2eRoot 'docker-compose.browser.yml')
    ) + $CommandArguments
    & docker compose @composeArguments
    $commandExitCode = $LASTEXITCODE
    if (-not $AllowFailure -and $commandExitCode -ne 0) {
        throw "Docker Compose command failed with exit code $commandExitCode."
    }
    return $commandExitCode
}

function ConvertTo-SafeDiagnosticLine {
    param([AllowEmptyString()][string]$Line)

    $sanitized = $Line
    $sanitized = $sanitized -replace '(?i)(DB_PASSWORD|DB_SCHEMA_PASSWORD|MYSQL_ROOT_PASSWORD|BOOTSTRAP_ADMIN_PASSWORD|BROWSER_QA_CASHIER_PASSWORD|QA_ADMIN_PASSWORD|QA_CASHIER_PASSWORD|TEST_DB_ROOT_PASSWORD)(?:\s*[:=]\s*|\s+)\S+', '$1=[REDACTED]'
    $sanitized = $sanitized -replace '(?i)\b(password|passwd|pwd|secret|token|credential|authorization|cookie|csrf|session)(?:\s*[:=]\s*|\s+)\S+', '$1=[REDACTED]'
    $sanitized = $sanitized -replace '\b[A-Fa-f0-9]{32,}\b', '[REDACTED]'
    return $sanitized
}

function Write-SafeFailureDiagnostics {
    param([Parameter(Mandatory = $true)][string]$Reason)

    Write-Host "Browser QA failure diagnostics: $Reason"
    $composeArguments = @(
        '--project-name', $composeProject,
        '--env-file', $envFile,
        '--file', (Join-Path $repoRoot 'docker-compose.yml'),
        '--file', (Join-Path $e2eRoot 'docker-compose.browser.yml')
    )

    try {
        Write-Host 'Container status (sanitized):'
        $statusLines = @(& docker compose @composeArguments ps --all 2>&1)
        if ($statusLines.Count -eq 0) {
            Write-Host 'No disposable containers were reported.'
        } else {
            $statusLines | Select-Object -Last 40 | ForEach-Object {
                Write-Host (ConvertTo-SafeDiagnosticLine -Line ([string]$_))
            }
        }
    } catch {
        Write-Host 'Container status was unavailable.'
    }

    try {
        Write-Host 'Application/database log tail (sanitized, max 80 lines):'
        $logLines = @(& docker compose @composeArguments logs --no-color --no-log-prefix --tail 80 app db 2>&1)
        if ($logLines.Count -eq 0) {
            Write-Host 'No application/database logs were reported.'
        } else {
            $logLines | Select-Object -Last 80 | ForEach-Object {
                Write-Host (ConvertTo-SafeDiagnosticLine -Line ([string]$_))
            }
        }
    } catch {
        Write-Host 'Application/database logs were unavailable.'
    }
}

function Wait-ForApplication {
    param([Parameter(Mandatory = $true)][string]$Url)

    for ($attempt = 1; $attempt -le 60; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec 5
            if ($response.StatusCode -eq 200) {
                return
            }
        } catch {
            # The container may still be building, starting Apache, or waiting
            # for the disposable database health check.
        }
        Start-Sleep -Seconds 2
    }
    throw 'The disposable browser QA application did not become ready.'
}

try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Docker is required for browser QA.'
    }
    if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
        throw 'Node.js and npm are required for browser QA.'
    }

    New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
    New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
    $appPort = Get-FreeLoopbackPort
    $mysqlPort = Get-FreeLoopbackPort
    $databaseName = "myshop_browser_qa_$runToken"
    $databaseUser = "myshop_browser_$runToken"
    $databasePassword = ([guid]::NewGuid().ToString('N')) + ([guid]::NewGuid().ToString('N'))
    $rootPassword = ([guid]::NewGuid().ToString('N')) + ([guid]::NewGuid().ToString('N'))
    $adminUsername = "qa_admin_$runToken"
    $adminFullName = 'Disposable QA Administrator'
    $adminPassword = ([guid]::NewGuid().ToString('N')) + 'A1'
    $cashierUsername = "qa_cashier_$runToken"
    $cashierFullName = 'Disposable QA Cashier'
    $cashierPassword = ([guid]::NewGuid().ToString('N')) + 'C1'
    $dataPrefix = "E2E_$runToken"

    $composeEnvironment = @(
        "APP_ENV=development",
        "APP_PORT=$appPort",
        "MYSQL_PORT=$mysqlPort",
        "DB_NAME=$databaseName",
        "DB_USER=$databaseUser",
        "DB_PASSWORD=$databasePassword",
        "MYSQL_ROOT_PASSWORD=$rootPassword",
        "TRUSTED_PROXY_IPS=",
        "HSTS_ENABLED=false",
        "PHP_BASE_IMAGE=php:8.3-apache-bookworm@sha256:69e641a40929c9db9d1ce43b982d46f6701ccdd5f7b4c32d5bd773cae6882393",
        "BROWSER_QA_MYSQL_IMAGE=mysql:8.4.3@sha256:106d5197fd8e4892980469ad42eb20f7a336bd81509aae4ee175d852f5cc4565",
        "BOOTSTRAP_ADMIN_USERNAME=$adminUsername",
        "BOOTSTRAP_ADMIN_FULL_NAME=$adminFullName",
        "BOOTSTRAP_ADMIN_PASSWORD=$adminPassword"
    )
    $composeEnvironment | Set-Content -LiteralPath $envFile -Encoding ascii

    @(
        "BROWSER_QA_CASHIER_USERNAME=$cashierUsername",
        "BROWSER_QA_CASHIER_FULL_NAME=$cashierFullName",
        "BROWSER_QA_CASHIER_PASSWORD=$cashierPassword",
        "BROWSER_QA_DATA_PREFIX=$dataPrefix"
    ) | Set-Content -LiteralPath $seedEnvFile -Encoding ascii

    Write-Host 'Starting disposable MyShop browser QA environment.'
    $composeStarted = $true
    Invoke-Compose -CommandArguments @('up', '--detach', '--build', 'db', 'app') | Out-Null
    Wait-ForApplication -Url "http://127.0.0.1:$appPort/health.php"

    Invoke-Compose -CommandArguments @('--profile', 'bootstrap', 'run', '--rm', 'bootstrap') | Out-Null
    Invoke-Compose -CommandArguments @('run', '--rm', '--no-deps', '--env-from-file', $seedEnvFile, 'app', 'php', 'scripts/browser-qa-seed.php') | Out-Null

    Push-Location $e2eRoot
    try {
        npm ci --ignore-scripts
        if ($LASTEXITCODE -ne 0) {
            throw "npm ci failed with exit code $LASTEXITCODE."
        }
        npx --no-install playwright install chromium
        if ($LASTEXITCODE -ne 0) {
            throw "Playwright Chromium installation failed with exit code $LASTEXITCODE."
        }
        $env:BASE_URL = "http://127.0.0.1:$appPort"
        $env:QA_ADMIN_USERNAME = $adminUsername
        $env:QA_ADMIN_PASSWORD = $adminPassword
        $env:QA_CASHIER_USERNAME = $cashierUsername
        $env:QA_CASHIER_PASSWORD = $cashierPassword
        $env:QA_DATA_PREFIX = $dataPrefix
        $env:E2E_OUTPUT_DIR = $outputDirectory
        npm test
        $exitCode = $LASTEXITCODE
        if ($exitCode -ne 0) {
            throw 'Browser QA test command failed.'
        }
    } finally {
        Pop-Location
    }
} catch {
    Write-SafeFailureDiagnostics -Reason 'the disposable browser QA command failed.'
    Write-Error 'Disposable browser QA failed.'
    $exitCode = 1
} finally {
    Remove-Item Env:BASE_URL, Env:QA_ADMIN_USERNAME, Env:QA_ADMIN_PASSWORD, Env:QA_CASHIER_USERNAME, Env:QA_CASHIER_PASSWORD, Env:QA_DATA_PREFIX, Env:E2E_OUTPUT_DIR -ErrorAction SilentlyContinue
    if ($composeStarted) {
        try {
            Invoke-Compose -CommandArguments @('down', '--rmi', 'local', '--volumes', '--remove-orphans') -AllowFailure | Out-Null
        } catch {
            Write-Error 'Unable to fully clean the disposable browser QA Compose project.'
            $exitCode = 1
        }
    }
    if (Test-Path -LiteralPath $tempRoot) {
        Remove-Item -LiteralPath $tempRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}

exit $exitCode

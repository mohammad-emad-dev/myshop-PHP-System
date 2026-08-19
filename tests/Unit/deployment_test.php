<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/scripts/production-preflight.php';

function deployment_valid_production_environment(): array
{
    return [
        'APP_ENV' => 'production',
        'DB_HOST' => 'db',
        'DB_PORT' => '3306',
        'DB_NAME' => 'myshop_production',
        'DB_USER' => 'myshop_runtime_prod',
        'DB_PASSWORD' => str_repeat('r', 31) . '1',
        'DB_SCHEMA_USER' => 'myshop_schema_deploy',
        'DB_SCHEMA_PASSWORD' => str_repeat('s', 31) . '2',
        'MYSQL_ROOT_PASSWORD' => str_repeat('t', 31) . '3',
        'PHP_BASE_IMAGE' => 'php:8.3-apache-bookworm@sha256:' . str_repeat('a', 64),
        'PRODUCTION_APP_IMAGE' => 'registry.example/myshop@sha256:' . str_repeat('b', 64),
        'PRODUCTION_MYSQL_IMAGE' => 'mysql:8.4.3@sha256:' . str_repeat('c', 64),
        'TRUSTED_PROXY_IPS' => '198.51.100.10',
        'HSTS_ENABLED' => 'true',
        'HSTS_MAX_AGE' => '31536000',
    ];
}

function run_deployment_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);

    $productionCompose = file_get_contents($repository . '/docker-compose.production.yml');
    $productionDockerfile = file_get_contents($repository . '/Dockerfile');
    $productionIni = file_get_contents($repository . '/docker/php-production.ini');
    $apacheVhost = file_get_contents($repository . '/docker/apache-vhost.conf');
    $publicHtaccess = file_get_contents($repository . '/public/.htaccess');
    $uploadHtaccess = file_get_contents($repository . '/public/uploads/.htaccess');
    $healthEndpoint = file_get_contents($repository . '/public/health.php');
    $readinessEndpoint = file_get_contents($repository . '/public/ready.php');
    $databaseConfig = file_get_contents($repository . '/config/db.php');
    $gitignore = file_get_contents($repository . '/.gitignore');
    $environmentExample = file_get_contents($repository . '/.env.example');
    $qualityWorkflow = file_get_contents($repository . '/.github/workflows/quality.yml');
    $preflightScript = file_get_contents($repository . '/scripts/production-preflight.php');
    $supplyChainScript = file_get_contents($repository . '/scripts/ci-supply-chain-check.php');
    $releaseIntegrityScript = file_get_contents($repository . '/scripts/release-integrity-check.php');
    $productionSmokeScript = file_get_contents($repository . '/scripts/run-production-smoke.ps1');
    $productionRunbook = file_get_contents($repository . '/docs/PRODUCTION-DEPLOYMENT.md');

    foreach ([
        $productionCompose,
        $productionDockerfile,
        $productionIni,
        $apacheVhost,
        $publicHtaccess,
        $uploadHtaccess,
        $healthEndpoint,
        $readinessEndpoint,
        $databaseConfig,
        $gitignore,
        $environmentExample,
        $qualityWorkflow,
        $preflightScript,
        $supplyChainScript,
        $releaseIntegrityScript,
        $productionSmokeScript,
        $productionRunbook,
    ] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Deployment fixture could not be read.');
    }

    $tests->assertContains('target: production', $productionCompose, 'Production Compose must use the production image stage.');
    $tests->assertContains('read_only: true', $productionCompose, 'Production application root must be read-only.');
    $tests->assertContains('restart: unless-stopped', $productionCompose, 'Production services need restart policies.');
    $tests->assertContains('production_uploads:/var/www/html/public/uploads', $productionCompose, 'Production uploads must use a named volume.');
    $tests->assertFalse(strpos($productionCompose, 'source: .\n        target: /var/www/html') !== false, 'Production application must not bind-mount the repository.');
    $tests->assertFalse(preg_match('/^  db:\s.*?^    ports:/ms', $productionCompose) === 1, 'Production MySQL must not publish a host port.');
    $tests->assertContains('/ready.php', $productionCompose, 'Production health check must use the readiness endpoint.');
    $tests->assertContains('http://127.0.0.1/health.php', $productionDockerfile, 'The base image health check must use the safe liveness endpoint.');
    $tests->assertContains('mysql:8.4.3@sha256:', $environmentExample, 'The documented production MySQL image must be digest-pinned.');
    $tests->assertContains('PRODUCTION_MYSQL_IMAGE:', str_replace('=', ':', $environmentExample), 'Production image settings must be documented.');

    $tests->assertContains('FROM ${PHP_BASE_IMAGE} AS base', $productionDockerfile, 'PHP base image must be configurable and version-pinned by deployment.');
    $tests->assertContains('FROM base AS production', $productionDockerfile, 'Dockerfile must have a production stage.');
    $tests->assertContains('COPY . /var/www/html', $productionDockerfile, 'Production code must be copied into the image.');
    $tests->assertContains('display_errors = Off', $productionIni, 'Production PHP must disable display_errors.');
    $tests->assertContains('display_startup_errors = Off', $productionIni, 'Production PHP must disable startup error display.');
    $tests->assertContains('error_log = /proc/self/fd/2', $productionIni, 'Production PHP errors must go to server-side container logs.');
    $tests->assertContains('zend.exception_ignore_args = On', $productionIni, 'Production PHP must avoid logging exception arguments.');

    $tests->assertContains('ErrorLog /proc/self/fd/2', $apacheVhost, 'Apache errors must go to stderr.');
    $tests->assertContains('CustomLog /proc/self/fd/1 myshop_combined', $apacheVhost, 'Apache access logs must go to stdout.');
    $tests->assertContains('request_id=', $apacheVhost, 'Apache access logs must include the response correlation ID.');
    foreach (['X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'X-Frame-Options'] as $header) {
        $tests->assertContains($header, $publicHtaccess, 'Public security header is missing: ' . $header);
    }
    $tests->assertContains('Require all denied', $uploadHtaccess, 'Uploads must deny executable extensions.');
    $tests->assertContains('Options -Indexes -ExecCGI', $uploadHtaccess, 'Uploads must disable indexing and CGI execution.');
    $tests->assertContains('.env', $gitignore, 'Environment files must remain ignored.');
    $tests->assertContains('!.env.example', $gitignore, 'The safe environment template must remain reviewable.');
    $tests->assertContains('TRUSTED_PROXY_IPS=', $environmentExample, 'Trusted proxy configuration must be documented.');
    $tests->assertContains('HSTS_ENABLED=false', $environmentExample, 'HSTS must default off for local HTTP development.');
    $tests->assertContains('cancel-in-progress: true', $qualityWorkflow, 'Obsolete Quality Gate runs must be cancelled.');
    $tests->assertContains('contents: read', $qualityWorkflow, 'Quality Gate jobs need read-only repository permissions.');
    $tests->assertContains('mysql:8.4.3@sha256:106d5197fd8e4892980469ad42eb20f7a336bd81509aae4ee175d852f5cc4565', $qualityWorkflow, 'CI MySQL must use the reviewed immutable digest.');
    $tests->assertContains('scripts/repository-security-check.php', $qualityWorkflow, 'Quality Gate must run the dependency-free repository security check.');
    $tests->assertContains('scripts/ci-supply-chain-check.php', $qualityWorkflow, 'Quality Gate must run the dependency-free CI supply-chain policy check.');
    $tests->assertContains('scripts/release-integrity-check.php', $qualityWorkflow, 'Quality Gate must emit safe release integrity evidence.');
    $tests->assertContains('production-runtime-smoke', $qualityWorkflow, 'Quality Gate must run the disposable production runtime smoke job.');
    $tests->assertContains('scripts/run-production-smoke.ps1', $qualityWorkflow, 'Quality Gate must invoke the production runtime smoke runner.');
    $tests->assertContains('docker-compose.production.yml', $qualityWorkflow, 'Quality Gate must validate the production Compose file.');
    $tests->assertContains('tests/validate_schema.php', $qualityWorkflow, 'Quality Gate must validate the schema and migration chain.');
    $tests->assertContains('production-preflight.php', $qualityWorkflow, 'Quality Gate must run the production preflight check.');
    $tests->assertContains('contents: read', $qualityWorkflow, 'Browser QA and deployment jobs must use read-only repository permissions.');

    $tests->assertContains('"status":"ok"', $healthEndpoint, 'Liveness endpoint must return a generic healthy response.');
    $tests->assertContains('"check":"liveness"', $healthEndpoint, 'Liveness endpoint must identify its liveness check.');
    $tests->assertFalse(strpos($healthEndpoint, "config/db.php") !== false, 'Liveness endpoint must not require database availability.');
    $tests->assertContains('SELECT 1', $readinessEndpoint, 'Readiness endpoint must probe database availability.');
    $tests->assertContains('http_response_code(503)', $readinessEndpoint, 'Readiness endpoint must fail with HTTP 503 when the database is unavailable.');
    $tests->assertContains('Content-Type: application/json; charset=utf-8', $databaseConfig, 'Database initialization readiness failures must declare JSON content type.');
    $tests->assertContains('exit(\'{"status":"not_ready","check":"database"}\')', $databaseConfig, 'Database initialization readiness failures must use the exact generic JSON contract.');
    $tests->assertContains('PRODUCTION_APP_IMAGE', $preflightScript, 'Production preflight must validate the application image reference.');
    $tests->assertContains('MYSQL_ROOT_PASSWORD', $preflightScript, 'Production preflight must validate root credential boundaries.');
    $tests->assertContains('immutable sha256 digest', $preflightScript, 'Production preflight must require immutable image references.');
    $tests->assertContains('full commit SHA', $supplyChainScript, 'CI supply-chain policy must require full action commit SHAs.');
    $tests->assertContains('schema_migration_version', $releaseIntegrityScript, 'Release integrity evidence must record the schema/migration version.');
    $tests->assertContains('/health.php', $productionSmokeScript, 'Production smoke must exercise the liveness endpoint.');
    $tests->assertContains('ExpectedStatus 503', $productionSmokeScript, 'Production smoke must verify readiness failure when MySQL is stopped.');
    $tests->assertContains('ReadonlyRootfs', $productionSmokeScript, 'Production smoke must inspect the read-only application root.');
    $tests->assertContains('no-new-privileges:true', $productionSmokeScript, 'Production smoke must inspect no-new-privileges.');
    $tests->assertContains('command -v git', $productionSmokeScript, 'Production smoke must verify Git is absent from the production image.');
    $tests->assertContains('display_errors', $productionSmokeScript, 'Production smoke must verify PHP error display is disabled.');
    $tests->assertContains('back up the database', strtolower($productionRunbook), 'Production runbook must require a database backup before migrations.');
    $tests->assertContains('rollback', strtolower($productionRunbook), 'Production runbook must document rollback behavior.');

    $validEnvironment = deployment_valid_production_environment();
    $validErrors = production_preflight_validate($validEnvironment, $productionCompose);
    $tests->assertSame([], $validErrors, 'A complete production configuration must pass preflight validation.');

    $missingEnvironment = $validEnvironment;
    $missingSecret = $missingEnvironment['DB_PASSWORD'];
    unset($missingEnvironment['DB_PASSWORD']);
    $missingErrors = production_preflight_validate($missingEnvironment, $productionCompose);
    $missingErrorText = implode("\n", $missingErrors);
    $tests->assertContains('DB_PASSWORD', $missingErrorText, 'Preflight must identify missing required settings.');
    $tests->assertFalse(strpos($missingErrorText, $missingSecret) !== false, 'Missing-setting errors must not contain secret values.');

    $placeholderEnvironment = $validEnvironment;
    $placeholderValue = 'replace_with_a_long_random_runtime_password';
    $placeholderEnvironment['DB_PASSWORD'] = $placeholderValue;
    $placeholderErrors = production_preflight_validate($placeholderEnvironment, $productionCompose);
    $placeholderErrorText = implode("\n", $placeholderErrors);
    $tests->assertContains('DB_PASSWORD', $placeholderErrorText, 'Preflight must reject placeholder credentials.');
    $tests->assertFalse(strpos($placeholderErrorText, $placeholderValue) !== false, 'Placeholder errors must not echo credential values.');

    $weakEnvironment = $validEnvironment;
    $weakEnvironment['MYSQL_ROOT_PASSWORD'] = 'short';
    $weakErrors = production_preflight_validate($weakEnvironment, $productionCompose);
    $tests->assertContains('at least 20 characters', implode("\n", $weakErrors), 'Preflight must reject weak credentials.');

    $invalidImageEnvironment = $validEnvironment;
    $invalidImageEnvironment['PRODUCTION_APP_IMAGE'] = 'myshop-app:production';
    $invalidImageEnvironment['PHP_BASE_IMAGE'] = 'php:8.3-apache-bookworm';
    $invalidImageEnvironment['PRODUCTION_MYSQL_IMAGE'] = 'mysql:8.4.3';
    $invalidImageErrors = production_preflight_validate($invalidImageEnvironment, $productionCompose);
    $invalidImageErrorText = implode("\n", $invalidImageErrors);
    $tests->assertContains('PRODUCTION_APP_IMAGE', $invalidImageErrorText, 'Preflight must reject mutable application image tags.');
    $tests->assertContains('PHP_BASE_IMAGE', $invalidImageErrorText, 'Preflight must reject mutable PHP base image tags.');
    $tests->assertContains('PRODUCTION_MYSQL_IMAGE', $invalidImageErrorText, 'Preflight must reject mutable MySQL image tags.');

    $invalidHstsEnvironment = $validEnvironment;
    $invalidHstsEnvironment['HSTS_ENABLED'] = 'maybe';
    $invalidHstsEnvironment['HSTS_MAX_AGE'] = '60';
    $invalidHstsErrors = production_preflight_validate($invalidHstsEnvironment, $productionCompose);
    $invalidHstsErrorText = implode("\n", $invalidHstsErrors);
    $tests->assertContains('HSTS_ENABLED must be true or false', $invalidHstsErrorText, 'Preflight must reject invalid HSTS enablement values.');
    $tests->assertContains('HSTS_MAX_AGE', $invalidHstsErrorText, 'Preflight must reject an unsafe HSTS duration.');

    $invalidProxyEnvironment = $validEnvironment;
    $invalidProxyEnvironment['TRUSTED_PROXY_IPS'] = '198.51.100.10,not-an-ip';
    $invalidProxyErrors = production_preflight_validate($invalidProxyEnvironment, $productionCompose);
    $tests->assertContains('TRUSTED_PROXY_IPS', implode("\n", $invalidProxyErrors), 'Preflight must reject invalid trusted proxy entries.');

    $runtimeComposeLine = '      DB_PASSWORD: ${DB_PASSWORD:?Set the restricted runtime DB_PASSWORD in the production secret manager}';
    $unsafeCompose = str_replace(
        $runtimeComposeLine,
        $runtimeComposeLine . PHP_EOL . '      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}',
        $productionCompose
    );
    $unsafeComposeErrors = production_preflight_validate($validEnvironment, $unsafeCompose);
    $tests->assertContains('MYSQL_ROOT_PASSWORD', implode("\n", $unsafeComposeErrors), 'Preflight must reject root credentials in the normal app service.');

    $unsafeSchemaCompose = str_replace(
        $runtimeComposeLine,
        $runtimeComposeLine . PHP_EOL . '      DB_SCHEMA_PASSWORD: ${DB_SCHEMA_PASSWORD}',
        $productionCompose
    );
    $unsafeSchemaErrors = production_preflight_validate($validEnvironment, $unsafeSchemaCompose);
    $tests->assertContains('DB_SCHEMA_PASSWORD', implode("\n", $unsafeSchemaErrors), 'Preflight must reject schema credentials in the normal app service.');

    $originalServer = $_SERVER;
    $originalTrustedProxyIps = getenv('TRUSTED_PROXY_IPS');
    try {
        putenv('TRUSTED_PROXY_IPS=198.51.100.10');
        $_SERVER = [
            'HTTPS' => 'off',
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $tests->assertFalse(is_https_request(), 'Untrusted forwarded HTTPS headers must be ignored.');

        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        $tests->assertTrue(is_https_request(), 'Configured trusted proxy HTTPS headers must be honored.');
    } finally {
        $_SERVER = $originalServer;
        if ($originalTrustedProxyIps === false) {
            putenv('TRUSTED_PROXY_IPS');
        } else {
            putenv('TRUSTED_PROXY_IPS=' . $originalTrustedProxyIps);
        }
    }

    return $tests->assertions();
}

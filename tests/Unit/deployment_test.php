<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

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
    $gitignore = file_get_contents($repository . '/.gitignore');
    $environmentExample = file_get_contents($repository . '/.env.example');
    $qualityWorkflow = file_get_contents($repository . '/.github/workflows/quality.yml');

    foreach ([
        $productionCompose,
        $productionDockerfile,
        $productionIni,
        $apacheVhost,
        $publicHtaccess,
        $uploadHtaccess,
        $gitignore,
        $environmentExample,
        $qualityWorkflow,
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
    $tests->assertContains('docker-compose.production.yml', $qualityWorkflow, 'Quality Gate must validate the production Compose file.');
    $tests->assertContains('tests/validate_schema.php', $qualityWorkflow, 'Quality Gate must validate the schema and migration chain.');

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

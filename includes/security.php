<?php

/**
 * Use the direct peer address. Forwarded headers are intentionally ignored
 * unless a trusted-proxy policy is added explicitly in a future change.
 */
function get_login_source_ip()
{
    $source_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($source_ip) || filter_var($source_ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $packed_ip = @inet_pton($source_ip);
    if ($packed_ip === false) {
        return false;
    }

    $canonical_ip = @inet_ntop($packed_ip);
    return is_string($canonical_ip) ? $canonical_ip : false;
}

/**
 * Return the authenticated actor from the server-side session only.
 * Request parameters must never be used as an audit actor identifier.
 */
function get_authenticated_staff_id()
{
    if (!isset($_SESSION['staff_id'])) {
        return null;
    }

    $staff_id = filter_var($_SESSION['staff_id'], FILTER_VALIDATE_INT);
    return $staff_id !== false && $staff_id > 0 ? (int)$staff_id : null;
}

/**
 * Create a server-generated correlation ID for the current HTTP request.
 * Client-provided IDs are deliberately ignored to prevent log injection and
 * collisions. The ID is safe for response headers and server logs only.
 */
function initialize_request_context()
{
    if (isset($GLOBALS['request_correlation_id'])) {
        return $GLOBALS['request_correlation_id'];
    }

    try {
        $request_id = bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        $request_id = hash('sha256', uniqid('', true) . mt_rand());
    }

    $GLOBALS['request_correlation_id'] = $request_id;

    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            header('X-Request-ID: ' . $request_id);
        }

        send_hsts_header();

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
        $method = preg_replace('/[^A-Z]/', '', $method);
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $path = substr(preg_replace('/[^A-Za-z0-9_\-\/.]/', '', $path), 0, 200);
        error_log('[request_id=' . $request_id . '] request_started method=' . $method . ' path=' . $path);

        register_shutdown_function(static function () use ($request_id) {
            error_log('[request_id=' . $request_id . '] request_completed status=' . (int)http_response_code());
        });
    }

    return $request_id;
}

function send_hsts_header()
{
    if (headers_sent()) {
        return;
    }

    $hsts_enabled = filter_var(getenv('HSTS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $hsts_max_age = filter_var(
        getenv('HSTS_MAX_AGE') ?: '31536000',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 300, 'max_range' => 63072000]]
    );
    if ($hsts_enabled && is_https_request() && $hsts_max_age !== false) {
        header('Strict-Transport-Security: max-age=' . $hsts_max_age);
    }
}

function get_request_correlation_id()
{
    return initialize_request_context();
}

/**
 * Add the correlation ID to new operational error messages without logging
 * request bodies, cookies, credentials, tokens, or authorization headers.
 */
function log_application_error($message)
{
    $message = is_scalar($message) ? (string)$message : 'Application error';
    error_log('[request_id=' . get_request_correlation_id() . '] ' . $message);
}

function get_trusted_proxy_ips()
{
    $configured = getenv('TRUSTED_PROXY_IPS');
    if ($configured === false || trim($configured) === '') {
        return [];
    }

    $trusted_ips = [];
    foreach (explode(',', $configured) as $candidate) {
        $candidate = trim($candidate);
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            $trusted_ips[] = $candidate;
        }
    }

    return array_values(array_unique($trusted_ips));
}

/**
 * Trust forwarded HTTPS state only from explicitly configured proxy IPs.
 */
function is_https_request()
{
    if (
        isset($_SERVER['HTTPS'])
        && strtolower((string)$_SERVER['HTTPS']) !== 'off'
        && $_SERVER['HTTPS'] !== ''
    ) {
        return true;
    }

    $remote_address = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($remote_address) || !in_array($remote_address, get_trusted_proxy_ips(), true)) {
        return false;
    }

    $forwarded_protocol = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $forwarded_protocol === 'https';
}

if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', 1800);
}

/**
 * Destroy the current session and expire its browser cookie.
 */
function destroy_current_session()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        unset($GLOBALS['current_staff_record']);
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();
    unset($GLOBALS['current_staff_record']);
}

/**
 * Starts a secure PHP session and expires idle sessions after 30 minutes.
 */
function start_secure_session()
{
    initialize_request_context();

    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');

        $is_secure = is_https_request();
        ini_set('session.cookie_secure', $is_secure ? '1' : '0');

        session_start([
            'cookie_lifetime' => 0,
            'cookie_path' => '/',
            'cookie_secure' => $is_secure,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax'
        ]);

        $now = time();
        $last_activity = $_SESSION['last_activity'] ?? null;
        if (
            $last_activity !== null
            && (!is_numeric($last_activity) || ($now - (int)$last_activity) > SESSION_IDLE_TIMEOUT)
        ) {
            destroy_current_session();
            return;
        }

        $_SESSION['last_activity'] = $now;
    }
}

/**
 * Generates a CSRF token and stores it in the session if not already set.
 */
function generate_csrf_token()
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies a given token against the one stored in the session.
 */
function verify_csrf_token($token)
{
    start_secure_session();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Returns the per-response nonce used by the remaining PHP-rendered inline
 * script and style blocks.
 */
function get_csp_nonce()
{
    if (!isset($GLOBALS['csp_nonce'])) {
        $GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
    }

    return $GLOBALS['csp_nonce'];
}

/**
 * Sends the enforced browser policy for HTML responses.
 *
 * Inline scripts and the remaining print stylesheet require the per-response
 * nonce. Inline style attributes are not permitted; unsafe-eval is never
 * permitted.
 */
function send_security_headers()
{
    $nonce = get_csp_nonce();

    if (!headers_sent()) {
        $policy = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "script-src-attr 'none'",
            "style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com",
            "style-src-attr 'none'",
            "img-src 'self' data:",
            "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "connect-src 'self'"
        ]);

        header('Content-Security-Policy: ' . $policy);
    }

    return $nonce;
}

/**
 * Returns verified SRI metadata for pinned external assets.
 * Null means the asset is local or intentionally documented as unsupported.
 */
function get_asset_integrity($asset_url)
{
    $integrity = [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'
            => 'sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN',
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'
            => 'sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css'
            => 'sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3',
        'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css'
            => 'sha384-QeY5AQZQxuccpv3R7xMnhIyrxSmzwsqI9A8hFrcDhljKd7rfQHZgnTh8gpCM5kWu',
        'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js'
            => 'sha384-5VBAkNWNEnA0Y+L5aWNg6fHumW6MdNSl4unYF6X6pHsXjltAvKa6VxLur8ZAQlzu',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'
            => 'sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4',
        'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js'
            => 'sha384-Yv5O+t3uE3hunW8uyrbpPW3iw6/5/Y7HitWJBLgqfMoA36NogMmy+8wWZMpn3HWc'
    ];

    return $integrity[$asset_url] ?? null;
}

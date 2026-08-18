<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_unit_tests(): int
{
    $tests = new TestContext();

    $tests->assertSame(
        'alert(&#039;x&#039;)',
        sanitize_input(" <script>alert('x')</script> "),
        'sanitize_input must strip tags and encode output-sensitive characters.'
    );
    $tests->assertSame('person@example.com', sanitize_email(' person@example.com '), 'Email sanitization failed.');
    $tests->assertSame('+1 555-123', sanitize_phone('+1 (555)-123'), 'Phone sanitization failed.');
    $tests->assertSame(42, sanitize_id('42'), 'Numeric ID validation failed.');
    $tests->assertSame(0, sanitize_id('not-an-id'), 'Invalid numeric IDs must become zero.');

    $tests->assertFalse(password_meets_policy('12345678901'), 'Eleven-character passwords must be rejected.');
    $tests->assertTrue(password_meets_policy('123456789012'), 'Twelve-character passwords must be accepted.');
    $tests->assertFalse(password_meets_policy(123456789012), 'Non-string passwords must be rejected.');

    $tests->assertSame('cashier@example.com', normalize_login_identifier(' Cashier@Example.com '), 'Login normalization failed.');
    $key = build_login_rate_limit_key(' Cashier ', '192.0.2.10');
    $tests->assertTrue(is_array($key), 'A valid account/IP pair must produce a rate-limit key.');
    $tests->assertSame(64, strlen($key['username_hash']), 'Rate-limit account identifiers must be hashed.');
    $tests->assertFalse(build_login_rate_limit_key('cashier', 'not-an-ip'), 'Invalid IP addresses must be rejected.');

    $_SERVER['REMOTE_ADDR'] = '2001:db8::10';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10';
    $tests->assertSame('2001:db8::10', get_login_source_ip(), 'The direct peer IP must be used.');
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);

    $tests->assertFalse(
        create_order(null, 1, [], 'sale', 1),
        'Empty orders must be rejected before database access.'
    );
    $tests->assertFalse(
        create_order(null, 1, [['product_id' => 1, 'quantity' => 1]], 'refund', 1),
        'Unknown order types must be rejected.'
    );
    $tests->assertFalse(
        create_order(null, 1, [['product_id' => 1, 'quantity' => 0]], 'sale', 1),
        'Non-positive quantities must be rejected.'
    );
    $tests->assertFalse(
        create_order(null, 1, [['product_id' => 1, 'quantity' => 2147483647], ['product_id' => 1, 'quantity' => 1]], 'sale', 1),
        'Duplicate quantities that overflow the supported range must be rejected.'
    );

    $sessionWasActive = session_status() === PHP_SESSION_ACTIVE;
    if (!$sessionWasActive) {
        session_start();
    }
    $_SESSION['csrf_token'] = 'test-csrf-token';
    $tests->assertFalse(verify_csrf_token('wrong-token'), 'Invalid CSRF tokens must be rejected.');
    $tests->assertTrue(verify_csrf_token('test-csrf-token'), 'Valid CSRF tokens must be accepted.');
    if (!$sessionWasActive) {
        destroy_current_session();
    }

    return $tests->assertions();
}

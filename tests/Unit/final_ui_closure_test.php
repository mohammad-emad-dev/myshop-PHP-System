<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * RED contracts for the final UI closure pass. These assertions cover only
 * presentation, accessibility semantics, and screenshot hygiene; the page
 * handlers and security contracts remain characterized separately.
 */
function run_final_ui_closure_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $login = file_get_contents($repository . '/public/login.php');
    $settings = file_get_contents($repository . '/public/settings.php');
    $audit = file_get_contents($repository . '/public/audit_log.php');
    $sidebar = file_get_contents($repository . '/includes/layouts/sidebar.php');
    $navbar = file_get_contents($repository . '/includes/layouts/navbar.php');
    $stylesheet = file_get_contents($repository . '/public/assets/css/style.css');
    $browserTests = file_get_contents($repository . '/e2e/tests/critical-journeys.spec.js');

    foreach ([$login, $settings, $audit, $sidebar, $navbar, $stylesheet, $browserTests] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Phase 6G source fixture could not be read.');
    }

    foreach ([
        'class="login-page login-shell',
        'class="login-shell__main container"',
        'class="card login-card',
        'class="card-header login-card__header',
        'class="card-body login-card__body',
        'class="login-form"',
        'login-submit',
        'class="card-footer login-card__footer',
        'id="main-content"',
        'name="csrf_token"',
        'name="username"',
        'name="password"',
        'autocomplete="username"',
        'autocomplete="current-password"',
    ] as $contract) {
        $tests->assertContains($contract, $login, 'Login presentation contract is missing: ' . $contract);
    }
    foreach ([
        'verify_csrf_token($token)',
        'login_rate_limit_check',
        'auth_verify_login',
        'Invalid credentials',
        'Too many login attempts',
    ] as $securityContract) {
        $tests->assertContains($securityContract, $login, 'Login security/error contract disappeared: ' . $securityContract);
    }
    $tests->assertFalse(strpos($login, 'bg-gradient-primary') !== false, 'Login must not retain the obsolete Bootstrap gradient hook.');

    foreach ([
        'class="container-fluid px-4 py-5 settings-page"',
        'class="data-page-header settings-page-header"',
        'settings-section settings-profile-card',
        'settings-section__header',
        'settings-security-callout',
        'settings-section settings-staff-panel',
        'class="table data-table settings-staff-table"',
        'id="addStaffModal"',
        'id="editStaffModal"',
        'data-bs-keyboard="true"',
        'data-bs-focus="true"',
        "event.key === 'Escape'",
        'modal.hide()',
        'aria-labelledby="addStaffModalLabel"',
        'aria-labelledby="editStaffModalLabel"',
    ] as $contract) {
        $tests->assertContains($contract, $settings, 'Settings presentation/accessibility contract is missing: ' . $contract);
    }
    foreach ([
        'auth_require_admin($conn)',
        'password_verify',
        'name="current_password"',
        'name="csrf_token"',
        'action" value="update_profile"',
        'action" value="create_staff"',
        'action" value="update_staff"',
    ] as $securityContract) {
        $tests->assertContains($securityContract, $settings, 'Settings security/mutation contract disappeared: ' . $securityContract);
    }
    $tests->assertFalse(strpos($settings, 'pulse-btn') !== false, 'Settings must not retain the decorative pulse action class.');

    foreach ([
        'class="container-fluid px-4 py-4 data-page audit-log-page"',
        'class="data-page-header audit-log-page__header"',
        'class="data-surface audit-log-surface"',
        'data-toolbar audit-log-toolbar',
        'class="data-table-shell audit-log-table-shell"',
        'tabindex="0"',
        'class="table data-table audit-log-table"',
        '<caption class="visually-hidden">Security audit events</caption>',
        'class="data-empty-state audit-log-empty"',
        'class="data-pagination audit-log-pagination"',
        'aria-label="Audit log pagination"',
        'id="audit_action"',
        'id="audit_page_size"',
    ] as $contract) {
        $tests->assertContains($contract, $audit, 'Audit-log presentation/accessibility contract is missing: ' . $contract);
    }
    foreach ([
        'auth_require_admin($conn)',
        'count_audit_logs($conn, $filters)',
        'get_audit_logs_page($conn, $filters, $page_size, $offset)',
        'name="action"',
        'name="actor"',
        'name="entity_type"',
        'name="outcome"',
        'name="date_from"',
        'name="date_to"',
    ] as $contract) {
        $tests->assertContains($contract, $audit, 'Audit-log read/filter contract disappeared: ' . $contract);
    }
    $tests->assertTrue(substr_count($audit, 'scope="col"') >= 7, 'Audit-log table headings must expose column scope.');

    $tests->assertContains('aria-current="page"', $sidebar, 'Active navigation must expose aria-current.');
    foreach (['id="sidebar-wrapper"', 'id="menu-toggle"', 'aria-controls="sidebar-wrapper"', 'id="main-content"'] as $shellContract) {
        $tests->assertContains($shellContract, $sidebar . $navbar, 'Shared shell behavior hook disappeared: ' . $shellContract);
    }

    foreach ([
        'Phase 6G: Final UI closure',
        '.login-shell',
        '.login-card',
        '.settings-page',
        '.audit-log-page',
        '.settings-security-callout',
        '.audit-log-table',
        '@media (prefers-reduced-motion: reduce)',
        ':focus-visible',
    ] as $styleContract) {
        $tests->assertContains($styleContract, $stylesheet, 'Phase 6G stylesheet contract is missing: ' . $styleContract);
    }
    $tests->assertFalse(strpos($stylesheet, 'background: linear-gradient(135deg, #0c1222') !== false, 'Obsolete login gradient declaration remains.');
    $tests->assertFalse(strpos($stylesheet, '@keyframes floatOrb') !== false, 'Obsolete animated login orb remains.');

    foreach ([
        "captureSanitizedScreenshot(page, 'login-final'",
        "captureSanitizedScreenshot(page, 'admin-settings-final'",
        "captureSanitizedScreenshot(page, 'admin-audit-log-final'",
        '.settings-staff-table td',
        '.audit-log-table td',
        "['/audit_log.php', 'audit-log']",
        "['/settings.php', 'settings-final']",
        'prefers-reduced-motion',
    ] as $browserContract) {
        $tests->assertContains($browserContract, $browserTests, 'Phase 6G browser/sanitization contract is missing: ' . $browserContract);
    }

    return $tests->assertions();
}

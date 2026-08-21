<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the Phase 6A shared UI foundation before the staged page
 * migration begins. These contracts deliberately fail until the foundation
 * and sanitized visual baseline are in place.
 */
function run_ui_redesign_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $stylesheetPath = $repository . '/public/assets/css/style.css';
    $stylesheet = file_get_contents($stylesheetPath);
    $specPath = $repository . '/docs/ui/PHASE-6A-UI-REDESIGN-SPEC.md';
    $header = file_get_contents($repository . '/includes/layouts/header.php');
    $navbar = file_get_contents($repository . '/includes/layouts/navbar.php');
    $sidebar = file_get_contents($repository . '/includes/layouts/sidebar.php');
    $footer = file_get_contents($repository . '/includes/layouts/footer.php');
    $script = file_get_contents($repository . '/public/assets/js/script.js');
    $ordersPage = file_get_contents($repository . '/public/orders.php');

    foreach ([$stylesheet, $header, $navbar, $sidebar, $footer, $script, $ordersPage] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Phase 6A UI source fixture could not be read.');
    }

    $tests->assertTrue(is_file($specPath), 'Phase 6A UI redesign specification is missing.');

    foreach ([
        '--color-canvas:',
        '--color-surface:',
        '--color-ink:',
        '--color-ink-muted: #4b6268;',
        '--color-brand-600:',
        '--space-1:',
        '--space-2:',
        '--space-3:',
        '--space-4:',
        '--radius-control:',
        '--radius-panel:',
        '--shadow-panel:',
        '--focus-ring:',
        'Phase 6A: shared design foundation',
    ] as $token) {
        $tests->assertContains($token, $stylesheet, 'Shared Phase 6A design token is missing: ' . $token);
    }

    foreach ([
        '@media (max-width: 991.98px)',
        '@media (max-width: 575.98px)',
        'margin-inline:',
        'padding-inline:',
        '@media (prefers-reduced-motion: reduce)',
        ':focus-visible',
        '#sidebar-wrapper.app-sidebar',
        '.app-topbar',
        '.app-main',
    ] as $layoutContract) {
        $tests->assertContains($layoutContract, $stylesheet, 'Shared shell contract is missing: ' . $layoutContract);
    }

    foreach ([
        'class="app-sidebar"',
        'class="navbar navbar-expand-lg navbar-light px-4 ui-navbar app-topbar"',
        'class="app-main"',
    ] as $shellClass) {
        $tests->assertContains($shellClass, $sidebar . $navbar . $navbar, 'Shared shell ownership marker is missing: ' . $shellClass);
    }

    foreach ([
        'id="wrapper"',
        'id="sidebar-wrapper"',
        'id="page-content-wrapper"',
        'id="menu-toggle"',
        'data-confirm-logout',
        'id="main-content"',
    ] as $behaviorHook) {
        $tests->assertContains($behaviorHook, $header . $navbar . $sidebar . $footer . $script . $ordersPage, 'Existing UI behavior hook disappeared: ' . $behaviorHook);
    }

    $baselineDirectory = $repository . '/docs/ui/baselines/phase-6a-before';
    foreach ([
        'mobile-375-login-dashboard.png',
        'tablet-768-login-dashboard.png',
        'desktop-1440-login-dashboard.png',
    ] as $baselineFile) {
        $tests->assertTrue(is_file($baselineDirectory . '/' . $baselineFile), 'Sanitized Phase 6A baseline is missing: ' . $baselineFile);
    }

    $tests->assertFalse(strpos($header . $navbar . $sidebar . $footer, 'style=') !== false, 'Shared layout must remain free of inline style attributes.');
    $tests->assertFalse(strpos($script, 'framework') !== false, 'Phase 6A must not introduce a frontend framework into the existing script.');

    return $tests->assertions();
}

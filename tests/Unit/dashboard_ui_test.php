<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the Dashboard-only Phase 6B visual boundary before the page
 * migration. The data, role, and navigation hooks are intentionally asserted
 * alongside the visual structure so the redesign cannot silently change them.
 */
function run_dashboard_ui_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $index = file_get_contents($repository . '/public/index.php');
    $stylesheet = file_get_contents($repository . '/public/assets/css/style.css');
    $browserTests = file_get_contents($repository . '/e2e/tests/critical-journeys.spec.js');

    foreach ([$index, $stylesheet, $browserTests] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Dashboard Phase 6B source fixture could not be read.');
    }

    foreach ([
        'dashboard-page',
        'dashboard-page-header',
        'dashboard-page-kicker',
        'dashboard-page-context',
        'dashboard-page-actions',
        'dashboard-kpi-grid',
        'class="dashboard-kpi-card',
        'class="dashboard-kpi-number"',
        'dashboard-panel',
        'dashboard-chart-frame',
        'dashboard-ranking-list',
        'dashboard-category-chart',
        'dashboard-alert-table',
        'dashboard-state',
        'dashboard-quick-list',
        'dashboard-quick-link',
    ] as $newContract) {
        $tests->assertContains($newContract, $index, 'Dashboard Phase 6B structure is missing: ' . $newContract);
    }

    foreach ([
        'dashboard-kpi-value',
        'dashboard-kpi-icon',
        'dashboard-section-title',
        'dashboard-chart-large',
        'dashboard-chart-category',
        'dashboard-progress',
        'dashboard-progress-bar',
        'dashboard-quick-heading',
        'quick-action-card',
        'quick-action-products',
        'quick-action-pos',
        'quick-action-customers',
        'quick-action-reports',
        'quick-action-icon',
        'quick-action-title',
        'quick-action-description',
    ] as $retiredContract) {
        $tests->assertSame(
            0,
            substr_count($index, $retiredContract),
            'Dashboard page still owns a retired Phase 6B selector: ' . $retiredContract
        );
    }

    foreach ([
        '.dashboard-page-header',
        '.dashboard-kpi-card',
        '.dashboard-panel',
        '.dashboard-chart-frame',
        '.dashboard-ranking-meter-fill',
        '.dashboard-alert-table',
        '.dashboard-quick-link',
        'var(--color-surface)',
        'var(--color-border)',
        'var(--color-brand-600)',
        'var(--focus-ring)',
        '@media (max-width: 767.98px)',
        '@media (max-width: 575.98px)',
        '@media (prefers-reduced-motion: reduce)',
    ] as $stylesheetContract) {
        $tests->assertContains($stylesheetContract, $stylesheet, 'Dashboard Phase 6B stylesheet contract is missing: ' . $stylesheetContract);
    }

    $phase6bStart = strpos($stylesheet, '/* Phase 6B: Dashboard visual migration */');
    $tests->assertTrue($phase6bStart !== false, 'Dashboard Phase 6B stylesheet boundary marker is missing.');
    if ($phase6bStart !== false) {
        $phase6bStyles = substr($stylesheet, $phase6bStart);
        $tests->assertFalse(
            strpos($phase6bStyles, 'linear-gradient') !== false,
            'Dashboard Phase 6B quick actions must not reintroduce decorative gradients.'
        );
    }

    foreach ([
        'dashboard_get_stats($conn, $dashboard_staff_id)',
        'dashboard_get_chart_data($conn, 7, $dashboard_staff_id)',
        'dashboard_get_inventory_valuation($conn)',
        'dashboard_get_top_selling_products($conn, 5, $dashboard_staff_id)',
        'dashboard_get_category_sales_distribution($conn, $dashboard_staff_id)',
        'inventory_get_low_stock_products($conn)',
        'auth_verify_login($conn)',
        'auth_is_admin($conn)',
        'id="dashboard-chart-data"',
        'id="dashboard-category-data"',
        'id="salesChart"',
        'id="categoryChart"',
        'data-progress=',
        'purchase_product_id=',
        'highlight=',
    ] as $behaviorContract) {
        $tests->assertContains($behaviorContract, $index, 'Dashboard behavior contract disappeared: ' . $behaviorContract);
    }

    foreach (['No sales or purchase activity', 'No category sales data available', 'All product stock levels are above'] as $emptyStateCopy) {
        $tests->assertContains($emptyStateCopy, $index, 'Dashboard intentional empty state is missing: ' . $emptyStateCopy);
    }

    $tests->assertContains('dashboard-kpi-number', $browserTests, 'Sanitized Dashboard screenshot masking must follow the migrated KPI selector.');
    $tests->assertSame(0, substr_count($browserTests, '.dashboard-kpi-value'), 'Browser QA must not depend on the retired KPI selector.');

    return $tests->assertions();
}

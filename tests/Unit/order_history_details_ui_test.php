<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the Phase 6F order history/detail/invoice presentation boundary.
 * Server-side reads, authorization, routes, and browser hooks remain unchanged.
 */
function run_order_history_details_ui_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $history = file_get_contents($repository . '/public/order_history.php');
    $details = file_get_contents($repository . '/public/get_order_details.php');
    $invoice = file_get_contents($repository . '/public/print_invoice.php');
    $stylesheet = file_get_contents($repository . '/public/assets/css/style.css');
    $browserTests = file_get_contents($repository . '/e2e/tests/critical-journeys.spec.js');

    foreach ([$history, $details, $invoice, $stylesheet, $browserTests] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Phase 6F source fixture could not be read.');
    }

    foreach ([
        'order-history-page',
        'data-page-header',
        'data-page-kicker',
        'data-page-context',
        'data-page-actions',
        'data-surface',
        'data-toolbar',
        'data-table-shell',
        'data-table',
        'data-pagination',
        'data-empty-state',
        'order-history-summary',
        'order-history-stat',
        'order-details-btn',
        'id="orderDetailsModal"',
        'data-modal',
        'data-modal__header',
        'data-modal__body',
        'data-modal__footer',
        'id="modalDetailsTableBody"',
        'id="downloadPdfBtn"',
        'id="exportReportModal"',
    ] as $contract) {
        $tests->assertContains($contract, $history, 'Order history presentation contract is missing: ' . $contract);
    }

    foreach ([
        'order-details-btn',
        'get_order_details.php?id=',
        'modalOrderId',
        'modalOrderDate',
        'modalPartyDetails',
        'modalPartyTitle',
        'modalPartyName',
        'modalPartyPhone',
        'modalPartyEmail',
        'modalPartyAddress',
        'modalDetailsTableBody',
        'modalOrderTotal',
        'printInvoiceItems',
        'printInvoiceTotal',
        'textContent',
    ] as $contract) {
        $tests->assertContains($contract, $history, 'Order detail JavaScript contract is missing: ' . $contract);
    }

    foreach ([
        'invoice-print-page',
        'invoice-box',
        'invoice-items-table',
        'grand-total',
        'window.print()',
        'orders_get_by_id($conn, $order_id, $staff_scope)',
        'orders_get_details($conn, $order_id, $staff_scope)',
    ] as $contract) {
        $tests->assertContains($contract, $invoice, 'Invoice presentation/security contract is missing: ' . $contract);
    }

    foreach ([
        "'order_history.php?' . http_build_query([",
        'name="type"',
        'id="orderPageSize"',
        'aria-label="Order history pagination"',
        'export_report.php',
        'type="button" class="btn btn-sm btn-outline-primary order-details-btn"',
        'auth_is_admin($conn)',
        'orders_count($conn, $order_scope_staff_id, $filter_type)',
        'orders_get_page($conn, $order_scope_staff_id, $filter_type, $page_size, $offset)',
        'orders_get_summary($conn, $order_scope_staff_id, $filter_type)',
    ] as $contract) {
        $tests->assertContains($contract, $history, 'Order history behavior contract is missing: ' . $contract);
    }

    foreach ([
        'auth_verify_login($conn, false)',
        'http_response_code(401)',
        'auth_is_admin($conn)',
        '$_SESSION[\'staff_id\']',
        'orders_get_by_id($conn, $order_id, $staff_scope)',
        'orders_get_details($conn, $order_id, $staff_scope)',
        'http_response_code(404)',
        'audit_log_current_actor',
    ] as $contract) {
        $tests->assertContains($contract, $details, 'Order detail authorization contract is missing: ' . $contract);
    }

    foreach ([
        'auth_verify_login($conn)',
        'sanitize_id($_GET[\'id\'])',
        'auth_is_admin($conn)',
        '$_SESSION[\'staff_id\']',
        'http_response_code(404)',
        'audit_log_current_actor',
        'send_security_headers()',
    ] as $contract) {
        $tests->assertContains($contract, $invoice, 'Invoice authorization contract is missing: ' . $contract);
    }

    foreach ([
        'Phase 6F: Order History, Order Details, and Invoice visual migration',
        '.order-history-page',
        '.order-history-stat',
        '.order-history-detail',
        '.invoice-print-page',
        'var(--color-surface)',
        'var(--color-border)',
        'var(--focus-ring)',
        '@media (max-width: 767.98px)',
        '@media (max-width: 575.98px)',
        '@media (prefers-reduced-motion: reduce)',
    ] as $contract) {
        $tests->assertContains($contract, $stylesheet, 'Phase 6F stylesheet contract is missing: ' . $contract);
    }

    $phase6fStart = strpos($stylesheet, '/* Phase 6F: Order History, Order Details, and Invoice visual migration */');
    $tests->assertTrue($phase6fStart !== false, 'Phase 6F stylesheet boundary marker is missing.');
    if ($phase6fStart !== false) {
        $phase6fStyles = substr($stylesheet, $phase6fStart);
        $tests->assertFalse(
            strpos($phase6fStyles, 'linear-gradient') !== false,
            'Phase 6F surfaces must not introduce decorative gradients.'
        );
    }

    foreach ([
        "loadPage(page, '/order_history.php?type=all'",
        "loadPage(page, '/order_history.php?type=sale'",
        'order-history-page',
        'order-details-btn',
        'order-details-modal',
        'print_invoice.php?id=',
        'cross-staff',
        'captureSanitizedScreenshot(page, `admin-order-history',
    ] as $contract) {
        $tests->assertContains($contract, $browserTests, 'Phase 6F browser contract is missing: ' . $contract);
    }

    return $tests->assertions();
}

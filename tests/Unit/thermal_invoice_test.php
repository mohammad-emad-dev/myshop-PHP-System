<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the Phase 6F.1 thermal invoice compatibility boundary.
 * The standalone invoice route must remain an 80mm receipt by default while
 * retaining its existing data, authorization, and print invocation contracts.
 */
function run_thermal_invoice_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $invoice = file_get_contents($repository . '/public/print_invoice.php');
    $details = file_get_contents($repository . '/public/get_order_details.php');
    $browserTests = file_get_contents($repository . '/e2e/tests/critical-journeys.spec.js');

    foreach ([$invoice, $details, $browserTests] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Thermal invoice source fixture could not be read.');
    }

    foreach ([
        '@page',
        'size: 80mm auto',
        'body.invoice-print-page',
        'width: 80mm',
        'max-width: 100vw',
        'overflow-x: hidden',
        'table-layout: fixed',
        'overflow-wrap: anywhere',
        'word-break: break-word',
        '@media print',
    ] as $contract) {
        $tests->assertContains($contract, $invoice, 'Default thermal CSS contract is missing: ' . $contract);
    }

    $tests->assertFalse(
        strpos($invoice, 'max-width: 860px') !== false,
        'The default invoice route must not retain the Phase 6F desktop/A4-like width.'
    );

    foreach ([
        'auth_verify_login($conn)',
        'sanitize_id($_GET[\'id\'])',
        'auth_is_admin($conn)',
        '$_SESSION[\'staff_id\']',
        'orders_get_by_id($conn, $order_id, $staff_scope)',
        'orders_get_details($conn, $order_id, $staff_scope)',
        'http_response_code(404)',
        'audit_log_current_actor',
        'send_security_headers()',
        'window.print()',
        'Invoice #',
        'invoice-items-table',
        'number_format($item[\'unit_price\'], 2)',
        'number_format($item[\'subtotal\'], 2)',
        'number_format($order[\'total_amount\'], 2)',
        'TOTAL:',
    ] as $contract) {
        $tests->assertContains($contract, $invoice, 'Invoice data or security contract is missing: ' . $contract);
    }

    foreach ([
        'auth_verify_login($conn, false)',
        'http_response_code(401)',
        '$_SESSION[\'staff_id\']',
        'orders_get_by_id($conn, $order_id, $staff_scope)',
        'orders_get_details($conn, $order_id, $staff_scope)',
        'http_response_code(404)',
    ] as $contract) {
        $tests->assertContains($contract, $details, 'Cross-staff order-detail contract is missing: ' . $contract);
    }

    foreach ([
        'captureSanitizedInvoiceScreenshot',
        "await invoicePage.emulateMedia({ media: 'print' })",
        'document.documentElement.scrollWidth',
        'admin-invoice-thermal',
        '#modalPartyDetails',
        '#modalPartyName',
        '#modalPartyPhone',
        '#modalPartyEmail',
        '#modalPartyAddress',
        '.ui-account-name',
    ] as $contract) {
        $tests->assertContains($contract, $browserTests, 'Thermal browser or screenshot-sanitization contract is missing: ' . $contract);
    }

    return $tests->assertions();
}

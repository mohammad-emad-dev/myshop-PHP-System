<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the shared Phase 6E CRUD visual boundary before the three
 * related people/category pages are migrated. Existing server, DOM, and
 * JavaScript contracts remain part of this source contract.
 */
function run_customers_suppliers_categories_ui_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $customers = file_get_contents($repository . '/public/customers.php');
    $suppliers = file_get_contents($repository . '/public/suppliers.php');
    $categories = file_get_contents($repository . '/public/categories.php');
    $stylesheet = file_get_contents($repository . '/public/assets/css/style.css');
    $browserTests = file_get_contents($repository . '/e2e/tests/critical-journeys.spec.js');

    foreach ([$customers, $suppliers, $categories, $stylesheet, $browserTests] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Phase 6E source fixture could not be read.');
    }

    foreach ([
        'customers-page',
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
        'data-action-group',
        'data-modal',
    ] as $sharedContract) {
        $tests->assertContains($sharedContract, $customers, 'Customers page is missing shared Phase 6E contract: ' . $sharedContract);
    }

    foreach ([
        'suppliers-page',
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
        'data-action-group',
        'data-modal',
    ] as $sharedContract) {
        $tests->assertContains($sharedContract, $suppliers, 'Suppliers page is missing shared Phase 6E contract: ' . $sharedContract);
    }

    foreach ([
        'categories-page',
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
        'data-action-group',
        'data-modal',
    ] as $sharedContract) {
        $tests->assertContains($sharedContract, $categories, 'Categories page is missing shared Phase 6E contract: ' . $sharedContract);
    }

    foreach ([
        'customer-row',
        'customer-contact',
        'customer-actions',
        'supplier-row',
        'supplier-contact',
        'supplier-actions',
        'category-row',
        'category-count',
        'category-actions',
        'default-category-state',
    ] as $operationalContract) {
        $tests->assertContains($operationalContract, $customers . $suppliers . $categories, 'Phase 6E operational contract is missing: ' . $operationalContract);
    }

    foreach ([
        'id="searchCustomer"',
        'name="search"',
        'id="customerPageSize"',
        'id="customersTable"',
        'data-bs-target="#addCustomerModal"',
        'id="addCustomerModal"',
        'id="editCustomerModal"',
        'edit-customer-btn',
        'data-customer-id=',
        'data-customer-name=',
        'data-customer-phone=',
        'data-customer-email=',
        'data-customer-address=',
        'name="action" value="delete"',
        'name="id"',
        'export_report.php?entity=customers',
        'people_count_customers($conn, $search)',
        'people_get_customers_page($conn, $search, $page_size, $offset)',
        'table-row-hidden',
        'delete-form',
        'verify_csrf_token($csrf_token)',
        'auth_is_admin($conn)',
        'audit_log_current_actor',
    ] as $customerContract) {
        $tests->assertContains($customerContract, $customers, 'Customers behavior contract disappeared: ' . $customerContract);
    }

    foreach ([
        'id="searchSupplier"',
        'name="search"',
        'id="supplierPageSize"',
        'id="suppliersTable"',
        'data-bs-target="#addSupplierModal"',
        'id="addSupplierModal"',
        'id="editSupplierModal"',
        'edit-supplier-btn',
        'data-supplier-id=',
        'data-supplier-name=',
        'data-supplier-phone=',
        'data-supplier-email=',
        'data-supplier-address=',
        'name="action" value="delete"',
        'name="id"',
        'export_report.php?entity=suppliers',
        'people_count_suppliers($conn, $search)',
        'people_get_suppliers_page($conn, $search, $page_size, $offset)',
        'table-row-hidden',
        'delete-form',
        'verify_csrf_token($csrf_token)',
        'auth_is_admin($conn)',
        'audit_log_current_actor',
    ] as $supplierContract) {
        $tests->assertContains($supplierContract, $suppliers, 'Suppliers behavior contract disappeared: ' . $supplierContract);
    }

    foreach ([
        'id="searchCategory"',
        'name="search"',
        'id="categoryPageSize"',
        'id="categoriesTable"',
        'data-bs-target="#addCategoryModal"',
        'id="addCategoryModal"',
        'id="editCategoryModal"',
        'edit-category-btn',
        'data-category-id=',
        'data-category-name=',
        'data-category-description=',
        'name="action" value="delete"',
        'name="id"',
        'categories_create($conn, $name, $description)',
        'categories_update($conn, $id, $name, $description)',
        'categories_delete($conn, $id)',
        'catalog_count_categories($conn, $search)',
        'catalog_get_categories_page($conn, $search, $page_size, $offset)',
        'table-row-hidden',
        'delete-form',
        'General',
        'verify_csrf_token($csrf_token)',
        'auth_is_admin($conn)',
        'audit_log_current_actor',
    ] as $categoryContract) {
        $tests->assertContains($categoryContract, $categories, 'Categories behavior contract disappeared: ' . $categoryContract);
    }

    foreach ([
        'Phase 6E: Customers, Suppliers, and Categories visual migration',
        '.data-page',
        '.data-surface',
        '.data-toolbar',
        '.data-table-shell',
        '.data-table',
        '.data-pagination',
        '.data-empty-state',
        '.data-modal',
        'var(--color-surface)',
        'var(--color-border)',
        'var(--focus-ring)',
        '@media (max-width: 767.98px)',
        '@media (max-width: 575.98px)',
        '@media (prefers-reduced-motion: reduce)',
    ] as $stylesheetContract) {
        $tests->assertContains($stylesheetContract, $stylesheet, 'Phase 6E stylesheet contract is missing: ' . $stylesheetContract);
    }

    $phase6eStart = strpos($stylesheet, '/* Phase 6E: Customers, Suppliers, and Categories visual migration */');
    $tests->assertTrue($phase6eStart !== false, 'Phase 6E stylesheet boundary marker is missing.');
    if ($phase6eStart !== false) {
        $phase6eStyles = substr($stylesheet, $phase6eStart);
        $tests->assertFalse(
            strpos($phase6eStyles, 'linear-gradient') !== false,
            'Phase 6E CRUD surfaces must not introduce decorative gradients.'
        );
    }

    foreach ([
        'admin-${surface.entity}s-after',
        'admin-categories-after',
        'customers.php?page_size=10',
        'suppliers.php?page_size=10',
        'categories.php?page_size=10',
        'getByLabel(surface.search)',
        'getByLabel(\'Search categories\')',
        'addCustomerModal',
        'editCustomerModal',
        'addSupplierModal',
        'editSupplierModal',
        'addCategoryModal',
        'editCategoryModal',
        'default-category-state',
        'invalid-crud-token',
    ] as $browserContract) {
        $tests->assertContains($browserContract, $browserTests, 'Browser QA contract is missing: ' . $browserContract);
    }

    return $tests->assertions();
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the complete compatibility facade and the final extracted
 * module boundaries before Phase 4G considers any legacy retirement.
 */
function run_facade_closure_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $facadePath = $repository . '/includes/functions.php';
    $facade = file_get_contents($facadePath);
    $people = file_get_contents($repository . '/includes/people.php');
    $validation = file_get_contents($repository . '/includes/validation.php');
    $baseline = file_get_contents($repository . '/docs/architecture/BASELINE.md');
    $dependencyMap = file_get_contents($repository . '/docs/architecture/DEPENDENCY-MAP.md');
    $responseContracts = file_get_contents($repository . '/docs/architecture/RESPONSE-CONTRACTS.md');

    $moduleNames = [
        'security.php',
        'validation.php',
        'pagination.php',
        'audit.php',
        'http.php',
        'auth.php',
        'catalog.php',
        'people.php',
        'inventory.php',
        'products.php',
        'orders.php',
        'dashboard.php',
        'uploads.php',
        'categories.php',
        'customers.php',
        'suppliers.php',
    ];
    foreach ([$facade, $people, $validation, $baseline, $dependencyMap, $responseContracts] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Facade closure source or documentation fixture could not be read.');
    }

    foreach ($moduleNames as $moduleName) {
        $include = "require_once __DIR__ . '/{$moduleName}';";
        $tests->assertSame(1, substr_count($facade, $include), 'Facade must load each shared module exactly once: ' . $moduleName);
    }

    $allFunctions = [
        'password_meets_policy', 'normalize_login_identifier', 'build_login_rate_limit_key',
        'login_rate_limit_log_failure', 'login_rate_limit_begin_transaction', 'login_rate_limit_rollback',
        'login_rate_limit_cleanup_expired', 'login_rate_limit_check', 'login_rate_limit_record_failure',
        'login_rate_limit_reset', 'verify_login', 'redirect', 'build_product_filter_sql', 'get_all_products',
        'get_pos_products', 'get_pos_product_by_barcode', 'count_products', 'get_products_page',
        'get_product_by_id', 'get_low_stock_products', 'log_stock_movement', 'get_stock_movements',
        'count_stock_movements', 'get_stock_movements_page', 'create_product', 'update_product',
        'delete_product', 'create_order', 'get_orders', 'get_orders_for_staff', 'count_orders',
        'get_order_summary', 'get_orders_page', 'get_order_by_id', 'get_order_details', 'get_dashboard_stats',
        'handle_image_upload', 'delete_newly_uploaded_image', 'get_chart_data', 'is_admin', 'require_admin',
        'get_staff_members', 'create_staff_member', 'update_staff_member', 'delete_staff_member',
        'set_staff_active', 'get_categories', 'count_categories', 'get_categories_page',
        'get_categories_for_selector', 'get_category_by_id', 'create_category', 'update_category',
        'delete_category', 'get_customers', 'count_customers', 'get_customers_page',
        'get_customers_for_selector', 'get_customer_by_id', 'create_customer', 'update_customer',
        'delete_customer', 'get_suppliers', 'count_suppliers', 'get_suppliers_page',
        'get_suppliers_for_selector', 'get_supplier_by_id', 'create_supplier', 'update_supplier',
        'delete_supplier', 'get_inventory_valuation', 'get_top_selling_products',
        'get_category_sales_distribution',
    ];
    preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(/m', $facade, $matches);
    $actualFunctions = $matches[1] ?? [];
    sort($allFunctions);
    sort($actualFunctions);
    $tests->assertSame($allFunctions, $actualFunctions, 'Facade function inventory changed without updating the closure classification contract.');

    $classificationGroups = [
        'delegation-only compatibility wrapper' => [
            'redirect', 'build_product_filter_sql', 'get_pos_products', 'get_pos_product_by_barcode',
            'count_products', 'get_products_page', 'get_product_by_id', 'get_low_stock_products',
            'log_stock_movement', 'count_stock_movements', 'get_stock_movements_page', 'create_product',
            'update_product', 'delete_product', 'create_order', 'count_orders', 'get_order_summary',
            'get_orders_page', 'get_order_by_id', 'get_order_details', 'get_dashboard_stats',
            'handle_image_upload', 'delete_newly_uploaded_image', 'get_chart_data', 'count_categories',
            'get_categories_page', 'get_categories_for_selector', 'create_category', 'update_category',
            'delete_category', 'count_customers', 'get_customers_page', 'get_customers_for_selector',
            'create_customer', 'update_customer', 'delete_customer', 'count_suppliers', 'get_suppliers_page',
            'get_suppliers_for_selector', 'create_supplier', 'update_supplier', 'delete_supplier',
            'get_inventory_valuation', 'get_top_selling_products', 'get_category_sales_distribution',
        ],
        'still-owned legacy service' => [
            'login_rate_limit_log_failure', 'login_rate_limit_begin_transaction', 'login_rate_limit_rollback',
            'login_rate_limit_cleanup_expired', 'login_rate_limit_check', 'login_rate_limit_record_failure',
            'login_rate_limit_reset', 'get_staff_members', 'create_staff_member', 'update_staff_member',
            'delete_staff_member', 'set_staff_active',
        ],
        'shared helper' => ['password_meets_policy', 'normalize_login_identifier', 'build_login_rate_limit_key'],
        'unbounded legacy loader' => ['get_all_products', 'get_stock_movements', 'get_orders', 'get_orders_for_staff', 'get_categories', 'get_customers', 'get_suppliers'],
        'uncalled legacy lookup' => ['get_category_by_id', 'get_customer_by_id', 'get_supplier_by_id'],
        'request/session/auth boundary' => ['verify_login', 'is_admin', 'require_admin'],
    ];
    $classifiedFunctions = [];
    foreach ($classificationGroups as $classification => $functions) {
        foreach ($functions as $functionName) {
            $classifiedFunctions[] = $functionName;
            $tests->assertContains('function ' . $functionName, $facade, 'Classified facade function is missing: ' . $functionName);
        }
    }
    sort($classifiedFunctions);
    $tests->assertSame($allFunctions, $classifiedFunctions, 'Every facade function must have exactly one architecture classification.');

    $delegationMap = [
        'redirect' => 'http_redirect',
        'build_product_filter_sql' => 'catalog_build_product_filter_sql',
        'get_pos_products' => 'catalog_get_pos_products',
        'get_pos_product_by_barcode' => 'catalog_get_pos_product_by_barcode',
        'count_products' => 'catalog_count_products',
        'get_products_page' => 'catalog_get_products_page',
        'get_product_by_id' => 'catalog_get_product_by_id',
        'get_low_stock_products' => 'inventory_get_low_stock_products',
        'log_stock_movement' => 'inventory_log_stock_movement',
        'count_stock_movements' => 'inventory_count_stock_movements',
        'get_stock_movements_page' => 'inventory_get_stock_movements_page',
        'create_product' => 'products_create', 'update_product' => 'products_update', 'delete_product' => 'products_delete',
        'create_order' => 'orders_create', 'count_orders' => 'orders_count', 'get_order_summary' => 'orders_get_summary',
        'get_orders_page' => 'orders_get_page', 'get_order_by_id' => 'orders_get_by_id', 'get_order_details' => 'orders_get_details',
        'get_dashboard_stats' => 'dashboard_get_stats', 'handle_image_upload' => 'uploads_handle_image',
        'delete_newly_uploaded_image' => 'uploads_delete_newly_uploaded_image', 'get_chart_data' => 'dashboard_get_chart_data',
        'count_categories' => 'catalog_count_categories', 'get_categories_page' => 'catalog_get_categories_page',
        'get_categories_for_selector' => 'catalog_get_categories_for_selector', 'create_category' => 'categories_create',
        'update_category' => 'categories_update', 'delete_category' => 'categories_delete',
        'count_customers' => 'people_count_customers', 'get_customers_page' => 'people_get_customers_page',
        'get_customers_for_selector' => 'people_get_customers_for_selector', 'create_customer' => 'customers_create',
        'update_customer' => 'customers_update', 'delete_customer' => 'customers_delete', 'count_suppliers' => 'people_count_suppliers',
        'get_suppliers_page' => 'people_get_suppliers_page', 'get_suppliers_for_selector' => 'people_get_suppliers_for_selector',
        'create_supplier' => 'suppliers_create', 'update_supplier' => 'suppliers_update', 'delete_supplier' => 'suppliers_delete',
        'get_inventory_valuation' => 'dashboard_get_inventory_valuation', 'get_top_selling_products' => 'dashboard_get_top_selling_products',
        'get_category_sales_distribution' => 'dashboard_get_category_sales_distribution',
    ];
    foreach ($delegationMap as $legacyName => $focusedName) {
        $wrapperPattern = '/function ' . preg_quote($legacyName, '/') . '\\s*\\([^)]*\\)\\s*\\{(?<body>.*?)\\n\\}/s';
        $matched = preg_match($wrapperPattern, $facade, $wrapperMatches) === 1;
        $tests->assertTrue($matched, 'Delegation wrapper is missing: ' . $legacyName);
        if (!$matched) {
            continue;
        }
        $body = trim($wrapperMatches['body']);
        $tests->assertContains($focusedName . '(', $body, 'Wrapper must call its focused owner: ' . $legacyName);
        $tests->assertFalse(
            preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|prepare|query|sanitize_|error_log)\b/i', $body) === 1,
            'Delegation wrapper must not retain SQL, sanitization, or logging: ' . $legacyName
        );
    }

    $authBoundaryMap = [
        'verify_login' => 'auth_verify_login',
        'is_admin' => 'auth_is_admin',
        'require_admin' => 'auth_require_admin',
    ];
    foreach ($authBoundaryMap as $legacyName => $focusedName) {
        $tests->assertContains($focusedName . '(', $facade, 'Auth boundary must delegate to the focused owner: ' . $legacyName);
        $tests->assertContains('global $conn;', $facade, 'Auth boundary global compatibility contract disappeared: ' . $legacyName);
    }

    $completedModules = [
        'catalog.php', 'products.php', 'categories.php', 'customers.php', 'suppliers.php',
        'people.php', 'inventory.php', 'orders.php', 'dashboard.php', 'uploads.php', 'validation.php',
    ];
    $expectedModuleIncludes = [
        'catalog.php' => ['pagination.php'],
        'products.php' => ['inventory.php', 'audit.php'],
        'categories.php' => [],
        'customers.php' => ['validation.php'],
        'suppliers.php' => ['validation.php'],
        'people.php' => ['pagination.php'],
        'inventory.php' => ['pagination.php', 'security.php', 'audit.php'],
        'orders.php' => ['inventory.php', 'audit.php', 'pagination.php'],
        'dashboard.php' => ['pagination.php'],
        'uploads.php' => [],
        'validation.php' => [],
    ];
    foreach ($completedModules as $moduleName) {
        $module = file_get_contents($repository . '/includes/' . $moduleName);
        $tests->assertTrue(is_string($module), 'Completed module could not be read: ' . $moduleName);
        $tests->assertFalse(strpos($module, "require_once __DIR__ . '/functions.php'") !== false, 'Completed module must not require the facade: ' . $moduleName);
        $tests->assertFalse(
            preg_match('/\$_SESSION|\$GLOBALS|\bglobal\s+\$[A-Za-z_]/', $module) === 1,
            'Completed module must not access session or global state: ' . $moduleName
        );
        foreach ($expectedModuleIncludes[$moduleName] as $requiredModule) {
            $tests->assertContains(
                "require_once __DIR__ . '/{$requiredModule}';",
                $module,
                'Completed module dependency declaration changed: ' . $moduleName . ' -> ' . $requiredModule
            );
        }
        preg_match_all("/require_once __DIR__ \\. \'\/([^']+)\'/", $module, $includeMatches);
        $actualIncludes = $includeMatches[1] ?? [];
        sort($actualIncludes);
        $expectedIncludes = $expectedModuleIncludes[$moduleName];
        sort($expectedIncludes);
        $tests->assertSame($expectedIncludes, $actualIncludes, 'Completed module has an unexpected dependency: ' . $moduleName);
    }
    $tests->assertContains('declare(strict_types=1);', $validation, 'Validation helpers must remain strict and pure.');
    $tests->assertFalse(strpos($validation, 'require_once') !== false, 'Validation helpers must not acquire dependencies.');
    $tests->assertFalse(strpos($validation, 'mysqli') !== false, 'Validation helpers must not depend on the database.');
    $tests->assertFalse(strpos($people, 'function create_customer') !== false, 'People must remain free of customer writes.');
    $tests->assertFalse(strpos($people, 'function update_customer') !== false, 'People must remain free of customer writes.');
    $tests->assertFalse(strpos($people, 'function delete_customer') !== false, 'People must remain free of customer writes.');
    $tests->assertFalse(strpos($people, 'function create_supplier') !== false, 'People must remain free of supplier writes.');
    $tests->assertFalse(strpos($people, 'function update_supplier') !== false, 'People must remain free of supplier writes.');
    $tests->assertFalse(strpos($people, 'function delete_supplier') !== false, 'People must remain free of supplier writes.');
    $tests->assertContains('Customer and Supplier writes are owned by dedicated mutation modules.', $people, 'People module boundary documentation is stale.');

    $directCallContracts = [
        'public/products.php' => ['products_create', 'products_update', 'products_delete', 'uploads_handle_image'],
        'public/categories.php' => ['categories_create', 'categories_update', 'categories_delete'],
        'public/customers.php' => ['customers_create', 'customers_update', 'customers_delete'],
        'public/suppliers.php' => ['suppliers_create', 'suppliers_update', 'suppliers_delete'],
        'public/orders.php' => ['orders_create'],
        'public/index.php' => ['dashboard_get_stats', 'dashboard_get_chart_data', 'dashboard_get_inventory_valuation', 'dashboard_get_top_selling_products', 'dashboard_get_category_sales_distribution'],
        'public/order_history.php' => ['orders_count', 'orders_get_summary', 'orders_get_page'],
        'public/get_order_details.php' => ['orders_get_by_id', 'orders_get_details'],
        'public/print_invoice.php' => ['orders_get_by_id', 'orders_get_details'],
        'public/pos_product_lookup.php' => ['catalog_get_pos_product_by_barcode'],
        'public/stock_movements.php' => [
            'catalog_get_product_by_id', 'catalog_get_pos_products',
            'inventory_adjust_stock', 'inventory_count_stock_movements', 'inventory_get_stock_movements_page',
        ],
    ];
    foreach ($directCallContracts as $relativePath => $focusedFunctions) {
        $page = file_get_contents($repository . '/' . $relativePath);
        foreach ($focusedFunctions as $focusedFunction) {
            $tests->assertContains($focusedFunction . '(', $page, 'Public caller must use focused ownership: ' . $relativePath . ' -> ' . $focusedFunction);
        }
    }
    foreach ([
        'public/products.php' => ['create_product', 'update_product', 'delete_product'],
        'public/categories.php' => ['create_category', 'update_category', 'delete_category'],
        'public/customers.php' => ['create_customer', 'update_customer', 'delete_customer'],
        'public/suppliers.php' => ['create_supplier', 'update_supplier', 'delete_supplier'],
        'public/orders.php' => ['create_order'],
        'public/index.php' => ['get_dashboard_stats', 'get_chart_data', 'get_inventory_valuation', 'get_top_selling_products', 'get_category_sales_distribution'],
    ] as $relativePath => $legacyFunctions) {
        $page = file_get_contents($repository . '/' . $relativePath);
        foreach ($legacyFunctions as $legacyFunction) {
            $tests->assertFalse(preg_match('/\b' . $legacyFunction . '\s*\(/', $page) === 1, 'Migrated page still calls the facade: ' . $relativePath . ' -> ' . $legacyFunction);
        }
    }
    foreach (['get_product_by_id', 'get_pos_products'] as $legacyFunction) {
        $stockPage = file_get_contents($repository . '/public/stock_movements.php');
        $tests->assertFalse(
            preg_match('/(?<!catalog_)\b' . $legacyFunction . '\s*\(/', $stockPage) === 1,
            'Stock movements page still calls the Catalog facade: ' . $legacyFunction
        );
    }

    $tests->assertContains('Phase 4F', $baseline, 'Current baseline must identify the Phase 4F closure review.');
    $tests->assertContains('Phase 4F', $dependencyMap, 'Current dependency map must identify the Phase 4F closure review.');
    $tests->assertContains('Phase 4F', $responseContracts, 'Current response contracts must identify the Phase 4F closure review.');

    return $tests->assertions();
}

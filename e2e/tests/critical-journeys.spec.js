const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const applicationOrigin = new URL(process.env.BASE_URL).origin;
const thirdPartyHosts = new Set([
  'cdnjs.cloudflare.com',
  'cdn.jsdelivr.net',
  'fonts.googleapis.com',
  'fonts.gstatic.com',
]);

const adminCredentials = {
  username: process.env.QA_ADMIN_USERNAME,
  password: process.env.QA_ADMIN_PASSWORD,
};
const cashierCredentials = {
  username: process.env.QA_CASHIER_USERNAME,
  password: process.env.QA_CASHIER_PASSWORD,
};
const dataPrefix = process.env.QA_DATA_PREFIX;

for (const [name, value] of Object.entries({
  QA_ADMIN_USERNAME: adminCredentials.username,
  QA_ADMIN_PASSWORD: adminCredentials.password,
  QA_CASHIER_USERNAME: cashierCredentials.username,
  QA_CASHIER_PASSWORD: cashierCredentials.password,
  QA_DATA_PREFIX: dataPrefix,
})) {
  if (!value) {
    throw new Error(`${name} must be supplied by the disposable browser QA runner.`);
  }
}

function safePath(url) {
  try {
    const parsed = new URL(url);
    return `${parsed.pathname}${parsed.search ? '?query' : ''}`;
  } catch {
    return '[unparseable-url]';
  }
}

function diagnosticsFor(page) {
  const diagnostics = {
    consoleErrors: [],
    applicationFailures: [],
    allowedFailures: [],
  };
  page.on('console', (message) => {
    if (message.type() === 'error') {
      const text = message.text();
      const location = message.location().url;
      const locationPath = location ? safePath(location) : '';
      let locationName = applicationOrigin;
      try {
        locationName = new URL(location || applicationOrigin).pathname;
      } catch {
        locationName = '';
      }
      const expectedForbidden = /Failed to load resource:.*403/i.test(text)
        && diagnostics.allowedFailures.some((allowed) => allowed.path === locationName);
      const cspThirdPartyNoise = /violates the following Content Security Policy directive:.*connect-src/i.test(text)
        && [...thirdPartyHosts].some((host) => text.includes(host));
      if (!expectedForbidden && !cspThirdPartyNoise) {
        diagnostics.consoleErrors.push(`${text.replace(/https?:\/\/([^/'"\s]+)[^'"\s]*/g, 'https://$1/[external-path]')} ${locationPath}`.trim());
      }
    }
  });
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin === applicationOrigin && response.status() >= 400) {
      const failure = `${response.status()} ${response.request().method()} ${safePath(response.url())}`;
      const expected = diagnostics.allowedFailures.some((allowed) => (
        allowed.status === response.status() && allowed.path === url.pathname
      ));
      if (!expected) {
        diagnostics.applicationFailures.push(failure);
      }
    }
  });
  page.on('requestfailed', (request) => {
    const url = new URL(request.url());
    if (url.origin === applicationOrigin) {
      diagnostics.applicationFailures.push(`request-failed ${request.method()} ${safePath(request.url())}`);
    } else if (!thirdPartyHosts.has(url.hostname)) {
      diagnostics.applicationFailures.push(`unexpected-external-request-failure ${url.hostname}`);
    }
  });
  return diagnostics;
}

async function currentPhpSessionId(page) {
  const cookies = await page.context().cookies();
  return cookies.find((cookie) => cookie.name === 'PHPSESSID')?.value ?? null;
}

async function login(page, credentials) {
  await page.goto('/login.php');
  const anonymousSession = await currentPhpSessionId(page);
  await page.getByLabel('Username').fill(credentials.username);
  await page.getByLabel('Password').fill(credentials.password);
  await page.getByRole('button', { name: 'Log In' }).click();
  await expect(page).toHaveURL(/\/index\.php$/);
  await expect(page.locator('#main-content').getByRole('heading', { name: 'Dashboard' })).toBeVisible();
  const authenticatedSession = await currentPhpSessionId(page);
  expect(
    anonymousSession !== null && authenticatedSession !== null && anonymousSession !== authenticatedSession,
    'Successful login must regenerate the PHP session identifier',
  ).toBe(true);
}

async function logout(page) {
  const authenticatedSession = await currentPhpSessionId(page);
  page.once('dialog', async (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Logout' }).first().evaluate((button) => button.click());
  await page.waitForURL(/\/login\.php$/);
  await expect(page.getByLabel('Username')).toBeVisible();
  const postLogoutSession = await currentPhpSessionId(page);
  expect(
    authenticatedSession !== null && postLogoutSession !== null && authenticatedSession !== postLogoutSession,
    'Logout must invalidate the authenticated PHP session identifier',
  ).toBe(true);
}

async function loadPage(page, route, heading) {
  const response = await page.goto(route);
  expect(response, `No response for ${route}`).not.toBeNull();
  expect(response.status(), `Unexpected HTTP status for ${route}`).toBeLessThan(400);
  await expect(page.getByRole('heading', { name: heading }).first()).toBeVisible();
}

function allowExpectedForbidden(page, route) {
  page.__myshopDiagnostics.allowedFailures.push({
    status: 403,
    path: new URL(route, applicationOrigin).pathname,
  });
}

async function runAccessibilityChecks(page, label) {
  await page.evaluate((source) => {
    if (!window.axe) {
      (0, eval)(source);
    }
  }, axeSource);
  const result = await page.evaluate(async () => window.axe.run(document, {
    resultTypes: ['violations'],
  }));
  const findings = result.violations.map((violation) => ({
    id: violation.id,
    impact: violation.impact,
    help: violation.help,
    targets: violation.nodes.slice(0, 5).map((node) => node.target.join(' ')),
    nodeCount: violation.nodes.length,
  }));
  const critical = findings.filter((finding) => finding.impact === 'critical');
  const serious = findings.filter((finding) => finding.impact === 'serious');
  if (findings.length > 0) {
    console.log(`Accessibility findings for ${label}: ${JSON.stringify(findings)}`);
  }
  if (serious.length > 0) {
    test.info().annotations.push({
      type: 'accessibility-finding',
      description: `${label}: ${JSON.stringify(serious)}`,
    });
  }
  expect(critical, `${label} has critical automated accessibility findings`).toEqual([]);
}

async function assertKeyboardFocusIsVisible(page, startingSelector, steps) {
  const startingControl = page.locator(startingSelector).first();
  await expect(startingControl).toBeVisible();
  await startingControl.focus();
  for (let index = 0; index < steps; index++) {
    await page.keyboard.press('Tab');
    const focus = await page.evaluate(() => {
      const element = document.activeElement;
      if (!element || element === document.body) {
        return { tag: null, visible: false, focusStyle: false };
      }
      const style = window.getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return {
        tag: element.tagName,
        visible: rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden',
        focusStyle: (style.outlineStyle !== 'none' && style.outlineWidth !== '0px') || style.boxShadow !== 'none',
      };
    });
    expect(focus.visible, 'Keyboard focus must remain on a visible control').toBeTruthy();
    expect(focus.focusStyle, 'Keyboard focus must have a visible focus treatment').toBeTruthy();
    expect(focus.tag, 'Keyboard focus must land on an element').not.toBeNull();
  }
}

async function captureSanitizedScreenshot(page, name) {
  const mask = page.locator([
    'input',
    'textarea',
    'select',
    'td',
    'tbody',
    '.dashboard-kpi-number',
    '.history-kpi-value',
    '.ui-account-name',
    '.ui-account-role',
    '.ui-avatar',
    '.ui-notification-menu',
    '.ui-count-text',
    '.ui-count-text-lg',
    '#modalOrderId',
    '#modalOrderDate',
    '#modalPartyDetails',
    '#modalPartyName',
    '#modalPartyPhone',
    '#modalPartyEmail',
    '#modalPartyAddress',
    '#modalOrderTotal',
    '#printInvoiceRef',
    '#printInvoiceDate',
    '#printInvoiceType',
    '#printInvoiceCashier',
    '#printInvoicePartyDetails',
    '#printInvoiceSubtotal',
    '#printInvoiceTotal',
    '.product-grid-container',
    '.pos-product-title',
    '.settings-staff-table td',
    '.audit-log-table td',
    '[data-qa-sensitive]',
  ].join(', '));
  await page.screenshot({
    path: path.join(process.env.E2E_OUTPUT_DIR || path.join(os.tmpdir(), 'myshop-browser-qa-output'), `${test.info().project.name}-${name}.png`),
    fullPage: true,
    animations: 'disabled',
    mask: await mask.all(),
    maskColor: '#000000',
  });
}

async function captureSanitizedInvoiceScreenshot(page, name) {
  const mask = page.locator([
    '.invoice-box h2',
    '.invoice-box p',
    '.invoice-box td',
    '.invoice-box .footer-note',
  ].join(', '));
  await page.screenshot({
    path: path.join(process.env.E2E_OUTPUT_DIR || path.join(os.tmpdir(), 'myshop-browser-qa-output'), `${test.info().project.name}-${name}.png`),
    fullPage: true,
    animations: 'disabled',
    mask: await mask.all(),
    maskColor: '#000000',
  });
}

async function captureSanitizedPosScreenshot(page, name) {
  const mask = page.locator([
    'input',
    'textarea',
    'select',
    'td',
    '.pos-product-title',
    '.pos-product-price',
    '.pos-product-stock',
    '.ui-account-name',
    '.ui-account-role',
    '.ui-avatar',
    '.ui-notification-menu',
    '[data-qa-sensitive]',
  ].join(', '));
  await page.screenshot({
    path: path.join(process.env.E2E_OUTPUT_DIR || path.join(os.tmpdir(), 'myshop-browser-qa-output'), `${test.info().project.name}-${name}.png`),
    fullPage: true,
    animations: 'disabled',
    mask: await mask.all(),
    maskColor: '#000000',
  });
}

test.beforeEach(async ({ page }) => {
  page.__myshopDiagnostics = diagnosticsFor(page);
});

test.afterEach(async ({ page }, testInfo) => {
  const diagnostics = page.__myshopDiagnostics;
  if (diagnostics) {
    expect(diagnostics.consoleErrors, 'The application must not emit critical browser console errors').toEqual([]);
    expect(diagnostics.applicationFailures, 'The application must not return unexpected 4xx/5xx responses').toEqual([]);
  }
  testInfo.annotations.push({
    type: 'inconclusive',
    description: 'No committed visual baseline exists; sanitized screenshots are captured for review only.',
  });
});

test('admin authentication, invalid login, protected redirect, and logout', async ({ page }) => {
  await page.goto('/login.php');
  await expect(page.getByRole('heading', { name: 'myshop' })).toBeVisible();
  await captureSanitizedScreenshot(page, 'login-final');
  await assertKeyboardFocusIsVisible(page, '#username', 2);
  await page.getByLabel('Username').fill('not-a-real-user');
  await page.getByLabel('Password').fill('not-a-real-password');
  await page.getByRole('button', { name: 'Log In' }).click();
  await expect(page.getByRole('alert')).toContainText('Invalid credentials');
  await expect(page.locator('body')).not.toContainText(/SQLSTATE|stack trace|password hash/i);

  await page.goto('/audit_log.php');
  await expect(page).toHaveURL(/\/login\.php$/);

  await login(page, adminCredentials);
  const logoutForm = page.locator('form[data-confirm-logout]').first();
  await logoutForm.locator('input[name="csrf_token"]').evaluate((input) => {
    input.value = 'invalid-logout-token';
  });
  allowExpectedForbidden(page, '/login.php');
  await Promise.all([
    page.waitForNavigation(),
    logoutForm.evaluate((form) => form.submit()),
  ]);
  await expect(page).toHaveURL(/\/login\.php$/);
  await expect(page.getByRole('alert')).toContainText('Security check failed. Invalid request token.');
  await page.goto('/index.php');
  await expect(page.locator('#main-content').getByRole('heading', { name: 'Dashboard' })).toBeVisible();
  await captureSanitizedScreenshot(page, 'login-dashboard');
  await logout(page);
  await page.goto('/settings.php');
  await expect(page).toHaveURL(/\/login\.php$/);
});

test('admin can load critical pages, search and paginate products, and access export routes', async ({ page }) => {
  await login(page, adminCredentials);

  await loadPage(page, '/index.php', 'Dashboard');
  await captureSanitizedScreenshot(page, 'admin-dashboard');

  await loadPage(page, '/products.php?page_size=10', 'Inventory Catalog');
  await page.getByLabel('Search products').fill(`${dataPrefix}_PRODUCT_`);
  await page.getByRole('button', { name: 'Apply' }).click();
  await expect(page).toHaveURL(/search=/);
  await expect(page.getByText(`${dataPrefix}_PRODUCT_12`)).toBeVisible();
  await expect(page.getByRole('link', { name: 'Next page' })).toBeVisible();
  await page.getByRole('link', { name: 'Next page' }).click();
  await expect(page).toHaveURL(/page=2/);
  await expect(page.getByText(`${dataPrefix}_PRODUCT_01`)).toBeVisible();
  await captureSanitizedScreenshot(page, 'admin-products');

  const pages = [
    ['/customers.php', /Customers/],
    ['/suppliers.php', /Suppliers/],
    ['/categories.php', /Categories/],
    ['/stock_movements.php', /Stock/],
    ['/order_history.php?type=all', /Transaction History/],
    ['/audit_log.php', /Security Audit Log/],
    ['/settings.php', /My Profile Settings/],
  ];
  for (const [route, heading] of pages) {
    await loadPage(page, route, heading);
    if (route === '/audit_log.php') {
      await expect(page.locator('#sidebar-wrapper a[aria-current="page"]')).toHaveAttribute('href', 'audit_log.php');
      await page.getByLabel('Action').fill(`${dataPrefix}_AUDIT`);
      await page.getByRole('button', { name: 'Filter' }).click();
      await expect(page).toHaveURL(/action=/);
      await captureSanitizedScreenshot(page, 'admin-audit-log-final');
      await page.getByRole('link', { name: 'Clear' }).click();
      await expect(page).toHaveURL(/audit_log\.php$/);
    }
    if (route === '/stock_movements.php') {
      await captureSanitizedScreenshot(page, 'admin-stock-ledger');
    }
    if (route === '/settings.php') {
      await expect(page.locator('#sidebar-wrapper a[aria-current="page"]')).toHaveAttribute('href', 'settings.php');
      await page.getByRole('button', { name: /Add Staff/ }).click();
      await expect(page.locator('#addStaffModal')).toBeVisible();
      await expect(page.locator('#addStaffModal .modal-title')).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(page.locator('#addStaffModal')).toBeHidden();
      await page.getByRole('button', { name: /Add Staff/ }).click();
      await expect(page.locator('#addStaffModal')).toBeVisible();
      await page.locator('#addStaffModal .btn-close').click();
      await expect(page.locator('#addStaffModal')).toBeHidden();
      await captureSanitizedScreenshot(page, 'admin-settings-final');
    }
  }
  await captureSanitizedScreenshot(page, 'admin-settings');

  await loadPage(page, '/products.php', 'Inventory Catalog');
  const exportHref = await page.getByRole('link', { name: /Export CSV/ }).getAttribute('href');
  expect(exportHref).toMatch(/^export_report\.php\?entity=products$/);
  const exportResult = await page.evaluate(async (href) => {
    const response = await fetch(href, { credentials: 'include' });
    return {
      status: response.status,
      type: response.headers.get('content-type'),
      disposition: response.headers.get('content-disposition'),
    };
  }, exportHref);
  expect(exportResult.status).toBe(200);
  expect(exportResult.type).toContain('text/csv');
  expect(exportResult.disposition).toContain('products.csv');
});

test('admin can review scoped order history, details, and invoice access while cross-staff cashier scope remains isolated', async ({ page }) => {
  await login(page, adminCredentials);
  await loadPage(page, '/orders.php', /POS Terminal/);

  const firstProduct = page.locator('.product-card[data-product-id]').first();
  await expect(firstProduct).toBeVisible();
  await firstProduct.click();
  await expect(page.locator('#completeOrderBtn')).toBeEnabled();
  await page.locator('#completeOrderBtn').click();
  await page.getByRole('button', { name: 'Yes, complete it!' }).click();
  await page.waitForLoadState('networkidle');

  await loadPage(page, '/order_history.php?type=all', /Transaction History/);
  await expect(page.locator('.order-history-page')).toBeVisible();
  await expect(page.locator('.order-history-surface .data-table-shell')).toBeVisible();
  await captureSanitizedScreenshot(page, 'admin-order-history');

  const detailButton = page.locator('.order-details-btn').first();
  await expect(detailButton).toBeVisible();
  const orderId = await detailButton.getAttribute('data-order-id');
  expect(orderId).not.toBeNull();
  await detailButton.click();
  await expect(page.locator('#orderDetailsModal')).toBeVisible();
  await expect(page.locator('.order-details-modal')).toBeVisible();
  await expect(page.locator('#modalDetailsTableBody')).toContainText(dataPrefix);
  await captureSanitizedScreenshot(page, 'admin-order-history-detail');

  const invoiceResult = await page.evaluate(async (id) => {
    const response = await fetch(`/print_invoice.php?id=${encodeURIComponent(id)}`, { credentials: 'include' });
    return { status: response.status, body: await response.text() };
  }, orderId);
  expect(invoiceResult.status).toBe(200);
  expect(invoiceResult.body).toContain('Invoice #');

  const invoicePage = await page.context().newPage();
  await invoicePage.setViewportSize({ width: 320, height: 800 });
  await invoicePage.emulateMedia({ media: 'print' });
  await invoicePage.addInitScript(() => {
    window.__myshopPrintInvoked = false;
    window.print = () => {
      window.__myshopPrintInvoked = true;
    };
  });
  const invoicePageResponse = await invoicePage.goto(`/print_invoice.php?id=${encodeURIComponent(orderId)}`);
  expect(invoicePageResponse).not.toBeNull();
  expect(invoicePageResponse.status()).toBe(200);
  await expect(invoicePage.locator('body.invoice-print-page')).toBeVisible();
  await expect(invoicePage.locator('.invoice-document .invoice-items-table')).toBeVisible();
  await expect(invoicePage.locator('.invoice-items-table tbody tr')).not.toHaveCount(0);
  await expect(invoicePage.locator('.invoice-document .grand-total')).toContainText('TOTAL:');
  expect(await invoicePage.evaluate(() => window.__myshopPrintInvoked)).toBe(true);
  const thermalLayout = await invoicePage.evaluate(() => {
    const body = document.body;
    const documentElement = document.documentElement;
    const invoice = document.querySelector('.invoice-box');
    const bodyStyle = window.getComputedStyle(body);
    return {
      viewportWidth: window.innerWidth,
      documentScrollWidth: documentElement.scrollWidth,
      bodyWidth: body.getBoundingClientRect().width,
      invoiceWidth: invoice ? invoice.getBoundingClientRect().width : 0,
      bodyOverflowX: bodyStyle.overflowX,
    };
  });
  expect(thermalLayout.documentScrollWidth).toBeLessThanOrEqual(thermalLayout.viewportWidth + 1);
  expect(thermalLayout.bodyWidth).toBeLessThanOrEqual(thermalLayout.viewportWidth + 1);
  expect(thermalLayout.invoiceWidth).toBeLessThanOrEqual(thermalLayout.viewportWidth + 1);
  expect(thermalLayout.bodyOverflowX).toBe('hidden');
  await captureSanitizedInvoiceScreenshot(invoicePage, 'admin-invoice-thermal');
  await invoicePage.close();

  await logout(page);
  await login(page, cashierCredentials);
  const crossStaffResponse = await page.request.get(`/get_order_details.php?id=${encodeURIComponent(orderId)}`);
  expect(crossStaffResponse.status()).toBe(404);
  const crossStaffInvoiceResponse = await page.request.get(`/print_invoice.php?id=${encodeURIComponent(orderId)}`);
  expect(crossStaffInvoiceResponse.status()).toBe(404);

  await loadPage(page, '/order_history.php?type=sale', /Transaction History/);
  await expect(page.locator('.order-history-page')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Purchases' })).toHaveCount(0);
  await captureSanitizedScreenshot(page, 'cashier-order-history');
});

test('admin Products and Stock Ledger surfaces support dense-data workflows', async ({ page }) => {
  await login(page, adminCredentials);

  await loadPage(page, '/products.php?page_size=10', 'Inventory Catalog');
  await expect(page.locator('.products-page')).toBeVisible();
  await expect(page.locator('.data-table-shell')).toBeVisible();
  await expect(page.locator('#productsTable')).toHaveClass(/data-table/);
  await expect(page.getByRole('link', { name: /Export CSV/ })).toHaveAttribute('href', 'export_report.php?entity=products');
  await expect(page.locator('#image')).toHaveAttribute('accept', 'image/*');
  await captureSanitizedScreenshot(page, 'admin-products-after');

  await page.getByLabel('Search products').fill(`${dataPrefix}_PRODUCT_`);
  await page.getByRole('button', { name: 'Apply' }).click();
  await expect(page).toHaveURL(/search=/);
  await expect(page.locator('.product-row')).toHaveCount(10);
  await page.locator('#pageSize').selectOption('50');
  await page.getByRole('button', { name: 'Apply' }).click();
  await expect(page.locator('.product-row')).toHaveCount(12);

  await page.getByRole('button', { name: /Add Product/ }).click();
  await expect(page.locator('#addProductModal')).toBeVisible();
  await expect(page.locator('#addProductModal form')).toHaveAttribute('enctype', 'multipart/form-data');
  await page.locator('#addProductModal .btn-close').click();
  await expect(page.locator('#addProductModal')).toBeHidden();
  await page.getByRole('button', { name: 'Edit product' }).first().click();
  await expect(page.locator('#editProductModal')).toBeVisible();
  await expect(page.locator('#edit_name')).not.toHaveValue('');
  await expect(page.locator('#edit_image')).toHaveAttribute('accept', 'image/*');
  await page.locator('#editProductModal .btn-close').click();
  await expect(page.locator('#editProductModal')).toBeHidden();
  await loadPage(page, '/stock_movements.php?page_size=10', /Stock Ledger/);
  await expect(page.locator('.inventory-page')).toBeVisible();
  await expect(page.locator('#stockMovementsTable')).toHaveClass(/data-table/);
  await expect(page.locator('#ledgerPageSize')).toHaveValue('10');
  await expect(page.getByRole('link', { name: /Export CSV/ })).toHaveAttribute('href', 'export_report.php?entity=stock');
  await expect(page.locator('#addMovementModal')).toHaveCount(1);
  await captureSanitizedScreenshot(page, 'admin-stock-ledger-after');
  const scopedProductOption = page.locator('#product_id option').filter({ hasText: `${dataPrefix}_PRODUCT_07` }).first();
  const scopedProductId = await scopedProductOption.getAttribute('value');
  expect(scopedProductId).not.toBeNull();
  await page.getByLabel('Filter by Product').selectOption(scopedProductId);
  await page.getByRole('button', { name: /Filter Ledger/ }).click();
  await expect(page).toHaveURL(/product_id=/);
  await expect(page.locator('.movement-row')).toHaveCount(1);
  await expect(page.locator('.movement-product')).toContainText(`${dataPrefix}_PRODUCT_07`);
  await expect(page.locator('.movement-quantity')).toBeVisible();
  await expect(page.locator('.movement-type')).toContainText('Purchase');
  await expect(page.locator('.movement-reason')).toBeVisible();
  await expect(page.locator('.movement-staff')).toBeVisible();
  await expect(page.locator('.movement-date')).toBeVisible();
  await page.getByRole('link', { name: 'Clear' }).click();
  await expect(page).toHaveURL(/stock_movements.php$/);
});

test('admin Customers, Suppliers, and Categories surfaces support shared CRUD workflows', async ({ page }) => {
  await login(page, adminCredentials);

  const peopleSurfaces = [
    {
      route: '/customers.php?page_size=10',
      heading: 'Customers',
      pageClass: '.customers-page',
      table: '#customersTable',
      row: '.customer-row',
      search: 'Search customers',
      searchId: '#searchCustomer',
      pageSize: '#customerPageSize',
      addButton: /Add Customer/,
      addModal: '#addCustomerModal',
      editModal: '#editCustomerModal',
      editButton: '.edit-customer-btn',
      exportHref: 'export_report.php?entity=customers',
      empty: '.customer-empty-state',
      entity: 'customer',
    },
    {
      route: '/suppliers.php?page_size=10',
      heading: 'Suppliers',
      pageClass: '.suppliers-page',
      table: '#suppliersTable',
      row: '.supplier-row',
      search: 'Search suppliers',
      searchId: '#searchSupplier',
      pageSize: '#supplierPageSize',
      addButton: /Add Supplier/,
      addModal: '#addSupplierModal',
      editModal: '#editSupplierModal',
      editButton: '.edit-supplier-btn',
      exportHref: 'export_report.php?entity=suppliers',
      empty: '.supplier-empty-state',
      entity: 'supplier',
    },
  ];

  for (const surface of peopleSurfaces) {
    await loadPage(page, surface.route, surface.heading);
    await captureSanitizedScreenshot(page, `admin-${surface.entity}s-after`);
    await expect(page.locator(surface.pageClass)).toBeVisible();
    await expect(page.locator(surface.table)).toHaveClass(/data-table/);
    await expect(page.locator('.data-table-shell')).toBeVisible();
    await expect(page.getByRole('link', { name: /Export CSV/ })).toHaveAttribute('href', surface.exportHref);
    await expect(page.locator(surface.pageSize)).toHaveValue('10');
    await expect(page.locator(surface.row).first()).toBeVisible();
    await assertKeyboardFocusIsVisible(page, surface.searchId, 1);

    const seededName = `${dataPrefix}_${surface.entity.toUpperCase()}`;
    await page.getByLabel(surface.search).fill(seededName);
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.locator(surface.row)).toHaveCount(1);
    await expect(page.locator(surface.row).first()).toContainText(seededName);

    await page.getByRole('button', { name: surface.addButton }).click();
    await expect(page.locator(surface.addModal)).toBeVisible();
    await expect(page.locator(`${surface.addModal} form input[name="csrf_token"]`)).toHaveCount(1);
    await page.locator(`${surface.addModal} input[name="name"]`).fill('');
    await page.locator(`${surface.addModal} button[type="submit"]`).click();
    await expect(page.locator(surface.addModal)).toBeVisible();
    await page.locator(`${surface.addModal} .btn-close`).click();
    await expect(page.locator(surface.addModal)).toBeHidden();

    const createdName = `${dataPrefix}_${surface.entity.toUpperCase()}_UI`;
    const updatedName = `${createdName}_UPDATED`;
    await page.getByRole('button', { name: surface.addButton }).click();
    await page.locator(`${surface.addModal} input[name="name"]`).fill(createdName);
    await page.locator(`${surface.addModal} input[name="phone"]`).fill('555-901');
    await page.locator(`${surface.addModal} input[name="email"]`).fill(`${surface.entity}.ui@example.test`);
    await page.locator(`${surface.addModal} textarea[name="address"]`).fill('Disposable UI workflow address');
    await Promise.all([
      page.waitForNavigation(),
      page.locator(`${surface.addModal} button[type="submit"]`).click(),
    ]);
    await expect(page.locator('body')).toHaveAttribute('data-feedback-success', /added successfully/);

    await page.getByLabel(surface.search).fill(createdName);
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.locator(surface.row)).toHaveCount(1);
    await page.locator(surface.row).first().locator(surface.editButton).click();
    await expect(page.locator(surface.editModal)).toBeVisible();
    await page.locator(`${surface.editModal} input[name="name"]`).fill(updatedName);
    await Promise.all([
      page.waitForNavigation(),
      page.locator(`${surface.editModal} button[type="submit"]`).click(),
    ]);
    await expect(page.locator('body')).toHaveAttribute('data-feedback-success', /updated successfully/);

    await page.getByLabel(surface.search).fill(updatedName);
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.locator(surface.row)).toHaveCount(1);
    await page.locator(surface.row).first().locator('form.delete-form button[type="submit"]').click();
    await expect(page.getByRole('button', { name: 'Yes, delete it!' })).toBeVisible();
    await Promise.all([
      page.waitForNavigation(),
      page.getByRole('button', { name: 'Yes, delete it!' }).click(),
    ]);
    await expect(page.locator('body')).toHaveAttribute('data-feedback-success', /deleted successfully/);
    await page.getByLabel(surface.search).fill(updatedName);
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.locator(surface.empty)).toBeVisible();
  }

  await loadPage(page, '/categories.php?page_size=10', /Categories/);
  await captureSanitizedScreenshot(page, 'admin-categories-after');
  await expect(page.locator('.categories-page')).toBeVisible();
  await expect(page.locator('#categoriesTable')).toHaveClass(/data-table/);
  await expect(page.locator('.data-table-shell')).toBeVisible();
  await expect(page.locator('#categoryPageSize')).toHaveValue('10');
  await assertKeyboardFocusIsVisible(page, '#searchCategory', 1);

  const defaultRow = page.locator('.category-row').filter({ hasText: 'General' }).first();
  await expect(defaultRow.locator('button[disabled]')).toHaveCount(2);
  await expect(defaultRow).toHaveClass(/default-category-state/);

  const seededCategory = `${dataPrefix}_CATEGORY`;
  await page.getByLabel('Search categories').fill(seededCategory);
  await page.getByRole('button', { name: 'Apply' }).click();
  const seededCategoryRow = page.locator('.category-row').filter({ hasText: seededCategory }).first();
  await expect(seededCategoryRow).toBeVisible();
  await seededCategoryRow.locator('.edit-category-btn').click();
  await expect(page.locator('#editCategoryModal')).toBeVisible();
  await expect(page.locator('#edit_name')).toHaveValue(seededCategory);
  await page.locator('#editCategoryModal .btn-close').click();
  await expect(page.locator('#editCategoryModal')).toBeHidden();

  allowExpectedForbidden(page, '/categories.php');
  const csrfStatus = await page.evaluate(async () => {
    const body = new URLSearchParams({
      csrf_token: 'invalid-crud-token',
      action: 'create',
      name: 'invalid browser category',
      description: 'must be rejected',
    });
    const response = await fetch('/categories.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    return response.status;
  });
  expect(csrfStatus).toBe(403);

  await page.getByRole('button', { name: /Add Category/ }).click();
  await expect(page.locator('#addCategoryModal')).toBeVisible();
  await expect(page.locator('#addCategoryModal form input[name="csrf_token"]')).toHaveCount(1);
  await page.locator('#addCategoryModal .btn-close').click();
  await expect(page.locator('#addCategoryModal')).toBeHidden();

  await logout(page);
  await login(page, cashierCredentials);
  for (const route of ['/customers.php', '/suppliers.php', '/categories.php']) {
    await page.goto(route);
    await expect(page.getByRole('link', { name: /Export CSV/ })).toHaveCount(0);
  }
});

test('authenticated POS barcode lookup returns the disposable catalog product', async ({ page }) => {
  await login(page, adminCredentials);

  const barcode = `${dataPrefix}_BARCODE_07`;
  const result = await page.evaluate(async (value) => {
    const response = await fetch(`/pos_product_lookup.php?barcode=${encodeURIComponent(value)}`, {
      credentials: 'include',
    });
    return {
      status: response.status,
      payload: await response.json(),
    };
  }, barcode);

  expect(result.status).toBe(200);
  expect(result.payload.product.name).toBe(`${dataPrefix}_PRODUCT_07`);
});

test('cashier POS workspace supports search, categories, barcode, cart controls, and safe checkout validation', async ({ page }) => {
  await login(page, adminCredentials);
  await loadPage(page, '/orders.php', /POS Terminal/);

  await expect(page.locator('.pos-page-header')).toBeVisible();
  await expect(page.locator('.pos-catalog-zone')).toBeVisible();
  await expect(page.locator('.pos-checkout-zone')).toBeVisible();

  const firstProduct = page.locator('.product-card[data-product-id]').first();
  await expect(firstProduct).toHaveAttribute('role', 'button');
  await firstProduct.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#cartTableBody .cart-item-row')).toHaveCount(1);
  await expect(page.locator('#cartCount')).toHaveText('1 Items');

  await firstProduct.click();
  await expect(page.locator('#cartCount')).toHaveText('2 Items');
  await page.locator('#cartTableBody .cart-qty-btn').last().click();
  await expect(page.locator('#cartCount')).toHaveText('3 Items');
  await page.locator('#cartTableBody .cart-qty-btn').first().click();
  await expect(page.locator('#cartCount')).toHaveText('2 Items');

  const category = page.locator('.category-pill').nth(1);
  await category.click();
  await expect(category).toHaveClass(/btn-primary/);
  await page.locator('.category-pill').first().click();

  await page.getByLabel('Search products by name or barcode').fill(`${dataPrefix}_PRODUCT_07`);
  await expect(page.locator('.product-item:not(.product-filter-hidden)')).toHaveCount(1);
  await page.getByLabel('Search products by name or barcode').fill('no-match-for-pos');
  await expect(page.locator('#productEmptyState')).toBeVisible();
  await page.getByLabel('Search products by name or barcode').fill('');

  await page.locator('#barcodeInput').fill(`${dataPrefix}_BARCODE_07`);
  await page.locator('#barcodeInput').press('Enter');
  await expect(page.locator('#cartTableBody .cart-item-row')).toHaveCount(2);

  await page.locator('#cartTableBody .btn.text-danger').last().click();
  await expect(page.locator('#cartTableBody .cart-item-row')).toHaveCount(1);

  await page.locator('label[for="typePurchase"]').click();
  await expect(page.locator('#orderTypeInput')).toHaveValue('purchase');
  await expect(page.locator('#formCustomerGroup')).toBeHidden();
  await expect(page.locator('#formSupplierGroup')).toBeVisible();
  await page.locator('label[for="typeSale"]').click();
  await expect(page.locator('#orderTypeInput')).toHaveValue('sale');
  await expect(page.locator('#formCustomerGroup')).toBeVisible();
  await expect(page.locator('#formSupplierGroup')).toBeHidden();
  await expect(page.locator('#completeOrderBtn')).toBeDisabled();

  allowExpectedForbidden(page, '/orders.php');
  const csrfStatus = await page.evaluate(async () => {
    const body = new URLSearchParams({
      csrf_token: 'invalid-pos-token',
      cart_data: '[]',
      complete_order: '1',
      order_type: 'sale',
    });
    const response = await fetch('/orders.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    return response.status;
  });
  expect(csrfStatus).toBe(403);
  await captureSanitizedPosScreenshot(page, 'cashier-orders-cart');
});

test('cashier can use sales and history but cannot access administrative or purchase controls', async ({ page }) => {
  await login(page, cashierCredentials);

  await loadPage(page, '/index.php', 'Dashboard');
  await loadPage(page, '/orders.php', /POS Terminal/);
  await expect(page.locator('#typeSale')).toBeVisible();
  await expect(page.locator('#typePurchase')).toHaveCount(0);
  await captureSanitizedScreenshot(page, 'cashier-orders');

  await loadPage(page, '/order_history.php?type=sale', /Transaction History/);
  await expect(page.getByRole('link', { name: 'Sales' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Purchases' })).toHaveCount(0);

  const restrictedRoutes = [
    '/audit_log.php',
    '/export_report.php?entity=products',
    '/categories.php',
    '/order_history.php?type=purchase',
  ];
  for (const route of restrictedRoutes) {
    const expectedForbidden = !route.includes('order_history.php');
    if (expectedForbidden) {
      allowExpectedForbidden(page, route);
    }
    const response = await page.goto(route);
    expect(response).not.toBeNull();
    if (route.includes('export_report.php')) {
      expect(response.status()).toBe(403);
    } else if (route.includes('order_history.php')) {
      await expect(page).toHaveURL(/order_history\.php/);
      await expect(page.getByRole('link', { name: 'Purchases' })).toHaveCount(0);
    } else {
      expect(response.status()).toBe(403);
    }
  }

  await loadPage(page, '/settings.php', /My Profile Settings/);
  await expect(page.getByRole('heading', { name: /Manage Staff Accounts/ })).toHaveCount(0);
});

test('responsive critical surfaces have no horizontal overflow at the configured viewport', async ({ page }) => {
  await login(page, adminCredentials);
  for (const route of ['/index.php', '/products.php', '/orders.php', '/settings.php']) {
    await page.goto(route);
    const layout = await page.evaluate(() => {
      const overflow = document.documentElement.scrollWidth - window.innerWidth;
      const visibleControls = [...document.querySelectorAll('a, button, input, select, textarea')]
        .filter((element) => {
          const rect = element.getBoundingClientRect();
          return rect.width > 0 && rect.height > 0;
        }).length;
      return {
        overflow,
        visibleControls,
        navigationVisible: Boolean(document.querySelector('nav, #sidebar-wrapper')),
        contentVisible: Boolean(document.querySelector('#page-content-wrapper')),
      };
    });
    expect(layout.overflow, `${route} must not overflow horizontally`).toBeLessThanOrEqual(1);
    expect(layout.visibleControls, `${route} must retain usable controls`).toBeGreaterThan(0);
    expect(layout.navigationVisible, `${route} must retain navigation`).toBeTruthy();
    expect(layout.contentVisible, `${route} must retain page content`).toBeTruthy();
  }
});

test('automated accessibility checks and keyboard smoke coverage report findings without claiming WCAG compliance', async ({ page }) => {
  await page.goto('/login.php');
  // Verify the stylesheet's prefers-reduced-motion path through the browser.
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await runAccessibilityChecks(page, 'login');
  await assertKeyboardFocusIsVisible(page, '#username', 2);
  await page.emulateMedia({ reducedMotion: null });

  await login(page, adminCredentials);
  for (const [route, label] of [
    ['/index.php', 'dashboard'],
    ['/products.php', 'products'],
    ['/orders.php', 'orders'],
    ['/settings.php', 'settings-final'],
    ['/audit_log.php', 'audit-log'],
  ]) {
    await page.goto(route);
    await runAccessibilityChecks(page, label);
  }
  await page.goto('/orders.php');
  await assertKeyboardFocusIsVisible(page, '#searchProduct', 3);
});

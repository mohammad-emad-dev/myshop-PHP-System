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

async function login(page, credentials) {
  await page.goto('/login.php');
  await page.getByLabel('Username').fill(credentials.username);
  await page.getByLabel('Password').fill(credentials.password);
  await page.getByRole('button', { name: 'Log In' }).click();
  await expect(page).toHaveURL(/\/index\.php$/);
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
}

async function logout(page) {
  page.once('dialog', async (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Logout' }).first().evaluate((button) => button.click());
  await page.waitForURL(/\/login\.php$/);
  await expect(page.getByLabel('Username')).toBeVisible();
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
    '.dashboard-kpi-value',
    '.history-kpi-value',
    '.ui-account-name',
    '.ui-account-role',
    '.ui-avatar',
    '.ui-notification-menu',
    '.ui-count-text',
    '.ui-count-text-lg',
    '.product-grid-container',
    '.pos-product-title',
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
  await assertKeyboardFocusIsVisible(page, '#username', 2);
  await page.getByLabel('Username').fill('not-a-real-user');
  await page.getByLabel('Password').fill('not-a-real-password');
  await page.getByRole('button', { name: 'Log In' }).click();
  await expect(page.getByRole('alert')).toContainText('Invalid credentials');
  await expect(page.locator('body')).not.toContainText(/SQLSTATE|stack trace|password hash/i);

  await page.goto('/audit_log.php');
  await expect(page).toHaveURL(/\/login\.php$/);

  await login(page, adminCredentials);
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
  await runAccessibilityChecks(page, 'login');
  await assertKeyboardFocusIsVisible(page, '#username', 2);

  await login(page, adminCredentials);
  for (const [route, label] of [
    ['/index.php', 'dashboard'],
    ['/products.php', 'products'],
    ['/orders.php', 'orders'],
    ['/settings.php', 'settings'],
  ]) {
    await page.goto(route);
    await runAccessibilityChecks(page, label);
  }
  await page.goto('/orders.php');
  await assertKeyboardFocusIsVisible(page, '#searchProduct', 3);
});

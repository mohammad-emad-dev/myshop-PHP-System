const os = require('node:os');
const path = require('node:path');
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.BASE_URL;
if (!baseURL) {
  throw new Error('BASE_URL must point to the disposable MyShop browser QA application.');
}

const outputDir = process.env.E2E_OUTPUT_DIR
  || path.join(os.tmpdir(), 'myshop-browser-qa-output');

module.exports = defineConfig({
  testDir: './tests',
  outputDir,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['list'],
    ['json', { outputFile: path.join(outputDir, 'results.json') }],
  ],
  use: {
    baseURL,
    browserName: 'chromium',
    headless: true,
    // Only explicit screenshots from the test helper are allowed; those
    // screenshots mask inputs, table cells, and KPI values before capture.
    screenshot: 'off',
    video: 'off',
    trace: 'off',
    acceptDownloads: false,
    ignoreHTTPSErrors: false,
  },
  projects: [
    { name: 'mobile-375', use: { ...devices['Desktop Chrome'], viewport: { width: 375, height: 812 } } },
    { name: 'tablet-768', use: { ...devices['Desktop Chrome'], viewport: { width: 768, height: 900 } } },
    { name: 'desktop-1440', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
  ],
});

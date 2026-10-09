import { defineConfig, devices } from '@playwright/test';

// The disposable Compose fixture must already be built, migrated and seeded.
export default defineConfig({
  testDir: './e2e/integration',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report/integration', open: 'never' }]],
  outputDir: 'test-results/integration',
  use: {
    baseURL: 'http://127.0.0.1:9187',
    extraHTTPHeaders: { Origin: 'http://127.0.0.1:9187' },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'], viewport: { width: 360, height: 800 } } },
  ],
});

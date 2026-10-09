import { defineConfig, devices } from '@playwright/test';
// Fast, isolated UI checks. Real Server sessions use playwright.integration.config.ts.
export default defineConfig({
  testDir: './e2e',
  testIgnore: '**/integration/**',
  forbidOnly: !!process.env.CI,
  workers: process.env.CI ? 1 : '50%',
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report/shell', open: 'never' }]],
  outputDir: 'test-results/shell',
  fullyParallel: true,
  use: { baseURL: 'http://127.0.0.1:9105', trace: 'retain-on-failure' },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'], viewport: { width: 360, height: 800 } } },
  ],
  webServer: {
    command: 'node e2e/serve-shell.mjs',
    url: 'http://127.0.0.1:9105',
    reuseExistingServer: false,
  },
});

import { defineConfig, devices } from '@playwright/test';
// Focused shell suite. RUSH-007 owns full-stack CI/browser infrastructure.
export default defineConfig({
  testDir: './e2e',
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

import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test, expect, chromium, type Page, type BrowserContext } from '@playwright/test';

async function ready(page: Page) {
  await page.evaluate(() => navigator.serviceWorker.ready);
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
  await expect(page.getByText('App ready for offline startup', { exact: true })).toBeVisible();
}

test('production PWA is installable and its shell reopens offline without HTTP cache', async ({
  page,
  context,
}, testInfo) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/sign-in');
  await ready(page);
  await page.getByText('Install RUSH', { exact: true }).click();
  await expect(page.getByText('Use your browser menu', { exact: false })).toBeVisible();
  const cdp = await context.newCDPSession(page);
  const manifest = await cdp.send('Page.getAppManifest');
  expect(manifest.errors).toEqual([]);
  const data = JSON.parse(manifest.data!) as Record<string, unknown>;
  expect(data).toMatchObject({
    id: '/rush-client',
    name: 'RUSH',
    short_name: 'RUSH',
    start_url: '/',
    scope: '/',
    display: 'standalone',
    display_override: ['standalone'],
  });
  expect((await cdp.send('Page.getInstallabilityErrors')).installabilityErrors).toEqual([]);
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  await context.setOffline(true);
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Sign in to RUSH' })).toBeVisible();
  await expect(page.getByText('App ready for offline startup', { exact: true })).toBeVisible();
  expect(
    await page.evaluate(async () => {
      await document.fonts.ready;
      return document.fonts.check('24px "Material Icons"');
    }),
  ).toBe(true);
  expect(errors).toEqual([]);
  await page.close();
  const reopened = await context.newPage();
  const reopenedCdp = await context.newCDPSession(reopened);
  await reopenedCdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  // Chromium's network emulation blocks requests independently from its
  // navigator.onLine hint on newly created targets; set that hint explicitly.
  await reopenedCdp.send('Network.overrideNetworkState', {
    offline: true,
    latency: 0,
    downloadThroughput: 0,
    uploadThroughput: 0,
  });
  await reopened.goto('/calendar?source=installed');
  expect(await reopened.evaluate(() => navigator.onLine)).toBe(false);
  await expect(reopened.getByRole('heading', { name: 'RUSH is offline' })).toBeVisible();
  await expect(reopened.getByRole('navigation')).toHaveCount(0);
  await expect(reopened.getByText('Reconnect to verify your account and continue.')).toBeVisible();
  await reopened.getByText('Install RUSH', { exact: true }).click();
  await expect(reopened.locator('.q-expansion-item__content')).toHaveCSS('overflow-y', 'visible');
  expect(await reopened.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
    true,
  );
  await reopened.screenshot({ path: testInfo.outputPath('offline-shell.png'), fullPage: true });
  await reopenedCdp.send('Network.overrideNetworkState', {
    offline: false,
    latency: 0,
    downloadThroughput: -1,
    uploadThroughput: -1,
  });
  await context.setOffline(false);
  // Restart online after both the browser hint and actual transport are restored.
  await reopened.reload();
  await expect(reopened.getByRole('heading', { name: 'Sign in to RUSH' })).toBeVisible();
});

test('Workbox stores app assets only and never substitutes the shell for Server requests', async ({
  page,
  context,
}, testInfo) => {
  for (const path of ['/sw.js', '/manifest.json']) {
    const response = await context.request.get(path);
    expect(response.ok()).toBe(true);
    expect(response.headers()['cache-control']).toBe('no-cache');
  }
  const missingWorker = await context.request.get('/workbox-missing.js');
  expect(missingWorker.status()).toBe(404);
  expect(await missingWorker.text()).not.toContain('<html');
  await page.goto('/sign-in');
  await ready(page);
  await page
    .getByLabel('Email', { exact: true })
    .fill(
      testInfo.project.name === 'mobile' ? 'sam.ranger@example.com' : 'quinn.ranger@example.com',
    );
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Ranger overview' })).toBeVisible();
  const response = await page.goto('/api/v1/session?cache-proof=1');
  expect(response?.fromServiceWorker()).toBe(false);
  expect(response?.headers()['content-type']).toContain('application/json');
  await page.goto('/account');
  await expect(page.getByRole('heading', { name: 'Your RUSH account' })).toBeVisible();
  const paths = await page.evaluate(async () => {
    const requests = await Promise.all(
      (await caches.keys()).map(async (key) => (await caches.open(key)).keys()),
    );
    return requests.flat().map((request) => new URL(request.url).pathname);
  });
  expect(paths).toContain('/index.html');
  expect(paths.some((path) => path.startsWith('/assets/'))).toBe(true);
  expect(
    paths.every(
      (path) =>
        path === '/index.html' ||
        path === '/manifest.json' ||
        /^\/assets\/.+\.(js|css|woff2?|ttf)$/.test(path) ||
        /^\/icons\/rush\.(png|svg)$/.test(path),
    ),
  ).toBe(true);
  await context.setOffline(true);
  const apiResult = await page.evaluate(async () => {
    try {
      await fetch('/api/v1/session');
      return 'unexpected response';
    } catch {
      return 'network failure';
    }
  });
  expect(apiResult).toBe('network failure');
  const serverPage = await context.newPage();
  for (const path of [
    '/api/v1/session?offline=1',
    '/admin',
    '/admin/main',
    '/login?next=/',
    '/logout',
    '/sanctum/csrf-cookie',
    '/up',
    '/vendor/orchid/',
    '/storage/private.json',
  ]) {
    await expect(serverPage.goto(path, { waitUntil: 'domcontentloaded' })).rejects.toThrow();
  }
  // The fixture owns this tab. Closing a Chromium offline error page directly
  // can stall; context teardown closes all tabs after collecting diagnostics.
});

test('a waiting service worker leaves open pages intact and activates after every tab closes', async ({
  page,
  context,
}) => {
  await page.goto('/sign-in');
  await ready(page);
  await page.getByLabel('Email', { exact: true }).fill('unsaved@example.test');
  const original = await page.evaluate(() => navigator.serviceWorker.controller!.scriptURL);
  await page.evaluate(async () => {
    // Changing the script URL triggers a real update using the same production
    // worker policy, without deploying a debug worker or replacing network responses.
    await navigator.serviceWorker.register('/sw.js?update-proof=1', { scope: '/' });
  });
  await expect
    .poll(() =>
      page.evaluate(async () => (await navigator.serviceWorker.getRegistration())?.waiting?.state),
    )
    .toBe('installed');
  expect(await page.evaluate(() => navigator.serviceWorker.controller!.scriptURL)).toBe(original);
  await expect(page.getByLabel('Email', { exact: true })).toHaveValue('unsaved@example.test');
  await expect(page.getByText('An app update is ready.', { exact: false })).toBeVisible();
  await page.close();
  const reopened = await context.newPage();
  await reopened.goto('/sign-in');
  await expect
    .poll(() => reopened.evaluate(() => navigator.serviceWorker.controller?.scriptURL))
    .toContain('?update-proof=1');
  await expect(reopened.getByRole('heading', { name: 'Sign in to RUSH' })).toBeVisible();
});

test('the precached shell survives closing the browser and restarting offline', async ({
  baseURL,
}, testInfo) => {
  const profile = await mkdtemp(join(tmpdir(), 'rush-pwa-'));
  let persistent: BrowserContext | undefined;
  try {
    const options = {
      baseURL: baseURL!,
      viewport: testInfo.project.use.viewport!,
      isMobile: testInfo.project.use.isMobile ?? false,
    };
    persistent = await chromium.launchPersistentContext(profile, options);
    const initial = persistent.pages()[0]!;
    await initial.goto('/sign-in');
    await ready(initial);
    await persistent.close();
    persistent = undefined;

    persistent = await chromium.launchPersistentContext(profile, { ...options, offline: true });
    const restarted = persistent.pages()[0]!;
    const cdp = await persistent.newCDPSession(restarted);
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await restarted.goto('/sign-in');
    await expect(restarted.getByRole('heading', { name: 'Sign in to RUSH' })).toBeVisible();
    await ready(restarted);
    expect(
      await restarted.evaluate(async () => {
        try {
          await fetch('/api/v1/session');
          return false;
        } catch {
          return true;
        }
      }),
    ).toBe(true);
  } finally {
    await persistent?.close();
    await rm(profile, { recursive: true, force: true });
  }
});

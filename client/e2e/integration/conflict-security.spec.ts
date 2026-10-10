import { mkdtemp, rm, mkdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { test, expect, chromium, type Page, type BrowserContext } from '@playwright/test';

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: /overview/ })).toBeVisible();
}
async function headers(context: BrowserContext, baseURL: string) {
  const cookie = (await context.cookies()).find((item) => item.name === 'XSRF-TOKEN')!;
  return {
    'X-XSRF-TOKEN': decodeURIComponent(cookie.value),
    Origin: baseURL,
    Accept: 'application/json',
  };
}
async function offline(context: BrowserContext, page: Page, value: boolean) {
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  await cdp.send('Network.overrideNetworkState', {
    offline: value,
    latency: 0,
    downloadThroughput: value ? 0 : -1,
    uploadThroughput: value ? 0 : -1,
  });
  await context.setOffline(value);
}
async function saveRange(page: Page) {
  await page.getByLabel('Start date', { exact: true }).fill('2026-11-07');
  await page.getByLabel('Start time', { exact: true }).fill('11:00 PM');
  await page.getByLabel('End date', { exact: true }).fill('2026-11-08');
  await page.getByLabel('End time', { exact: true }).fill('07:00 AM');
  await page.getByRole('button', { name: 'Save unavailability' }).click();
}
async function screenshot(page: Page, name: string) {
  const directory = resolve('../docs/evidence/RUSH-013');
  await mkdir(directory, { recursive: true });
  await page.screenshot({ path: join(directory, name + '.png'), fullPage: true });
}
type Identity = { memberships: { id: string; organization: { id: string } }[] };

test('offline restart reconciles a real Management assignment change without rewriting it', async ({
  browser,
  baseURL,
}, testInfo) => {
  test.setTimeout(90_000);
  const profile = await mkdtemp(join(tmpdir(), 'rush-conflict-'));
  const options = {
    baseURL: baseURL!,
    viewport: testInfo.project.use.viewport!,
    timezoneId: 'America/Los_Angeles',
  };
  let context: BrowserContext | undefined;
  const manager = await browser.newContext({ baseURL: baseURL! });
  try {
    context = await chromium.launchPersistentContext(profile, options);
    let page = context.pages()[0]!;
    await signIn(
      page,
      testInfo.project.name === 'mobile'
        ? 'morgan.ranger@example.com'
        : 'taylor.ranger@example.com',
    );
    const identity = (await (await context.request.get('/api/v1/session')).json()) as Identity;
    const member = identity.memberships[0]!;
    const managerPage = await manager.newPage();
    await signIn(managerPage, 'avery.management@example.com');
    const managerHeaders = await headers(manager, baseURL!);
    const id = crypto.randomUUID();
    const url = '/api/v1/organizations/' + member.organization.id + '/official-assignments/' + id;
    const assignment = {
      membership_id: member.id,
      starts_at: '2026-11-08T18:00:00Z',
      ends_at: '2026-11-09T02:00:00Z',
      expected_revision: null as number | null,
      reason: 'Offline conflict proof',
    };
    expect(
      (await manager.request.put(url, { headers: managerHeaders, data: assignment })).ok(),
    ).toBe(true);
    await page.goto('/availability');
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
    await offline(context, page, true);
    await saveRange(page);
    const pending = page.getByTestId('availability-entry').filter({ hasText: 'Pending' });
    await expect(pending).toHaveCount(1);
    const recordId = await pending.getAttribute('data-record-id');
    await page.reload();
    await expect(page.getByTestId('availability-entry').filter({ hasText: 'Pending' })).toHaveCount(
      1,
    );
    await context.close();
    context = undefined;
    const changed = await manager.request.put(url, {
      headers: managerHeaders,
      data: {
        ...assignment,
        expected_revision: 1,
        starts_at: '2026-11-08T08:00:00Z',
        ends_at: '2026-11-08T16:00:00Z',
      },
    });
    expect(changed.ok()).toBe(true);
    expect(((await changed.json()) as { revision: number }).revision).toBe(2);
    context = await chromium.launchPersistentContext(profile, { ...options, offline: true });
    page = context.pages()[0]!;
    await offline(context, page, true);
    await page.goto('/availability');
    await expect(page.getByTestId('availability-entry').filter({ hasText: 'Pending' })).toHaveCount(
      1,
    );
    await offline(context, page, false);
    await page.reload();
    const row = page.locator('[data-record-id="' + recordId + '"]');
    await expect(row).toContainText('Synced — accepted by Server');
    await expect(row.getByTestId('assignment-conflict')).toContainText('Assignment revision 2');
    await expect(row).toContainText('11:00 PM');
    await expect(row).toContainText('7:00 AM');
    await page.reload();
    await expect(row.getByTestId('assignment-conflict')).toBeVisible();
    await screenshot(page, testInfo.project.name + '-official-conflict');
    // Revision 2 still holds after reconciliation; only Management can resolve it.
    const resolved = await manager.request.put(url, {
      headers: managerHeaders,
      data: { ...assignment, expected_revision: 2 },
    });
    expect(resolved.ok()).toBe(true);
    await page.reload();
    await expect(row.getByTestId('assignment-conflict')).toHaveCount(0);
    await expect(row).toContainText('Synced — accepted by Server');
  } finally {
    await context?.close();
    await manager.close();
    await rm(profile, { recursive: true, force: true });
  }
});

test('explicit logout keeps unsent work private across tabs and account switching', async ({
  page,
  context,
  baseURL,
}, testInfo) => {
  test.setTimeout(90_000);
  const owner =
    testInfo.project.name === 'mobile' ? 'sam.ranger@example.com' : 'quinn.ranger@example.com';
  await signIn(page, owner);
  await page.goto('/availability');
  await expect(page.getByText('0 pending change(s).', { exact: false })).toBeVisible();
  await offline(context, page, true);
  await saveRange(page);
  const pending = page.getByTestId('availability-entry').filter({ hasText: 'Pending' });
  await expect(pending).toHaveCount(1);
  const id = await pending.getAttribute('data-record-id');
  // Hold transport offline while allowing the real cookie logout/account requests.
  await context.route('**/sync/**', (route) => route.abort('internetdisconnected'));
  await offline(context, page, false);
  await page.goto('/account');
  const other = await context.newPage();
  await other.goto('/availability');
  await expect(other.locator('[data-record-id="' + id + '"]')).toBeVisible();
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Keep saved work and sign out' })).toBeVisible();
  await screenshot(page, testInfo.project.name + '-preserve-signout');
  await page.getByRole('button', { name: 'Keep saved work and sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Sign in to RUSH' })).toBeVisible();
  await expect(other.locator('[data-record-id="' + id + '"]')).toHaveCount(0);
  await signIn(page, 'jordan.ranger@example.com');
  await page.goto('/availability');
  await expect(page.locator('[data-record-id="' + id + '"]')).toHaveCount(0);
  await page.goto('/account');
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Sign in to RUSH' })).toBeVisible();
  await context.unroute('**/sync/**');
  await signIn(page, owner);
  await page.goto('/availability');
  await expect(page.locator('[data-record-id="' + id + '"]')).toContainText(
    'Synced — accepted by Server',
  );
  const requestHeaders = await headers(context, baseURL!);
  expect((await context.request.post('/logout', { headers: requestHeaders })).ok()).toBe(true);
  await saveRange(page);
  await expect(page.getByRole('heading', { name: 'Session needs attention' })).toBeVisible();
  await expect(page.getByText('Your saved intent is retained', { exact: false })).toBeVisible();
  await screenshot(page, testInfo.project.name + '-session-recovery');
  await page.getByRole('link', { name: 'Sign in again' }).click();
  await signIn(page, owner);
  await page.goto('/availability');
  await expect(page.locator('[data-record-id="' + id + '"]')).toContainText(
    'Synced — accepted by Server',
  );
});

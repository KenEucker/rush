import { mkdtemp, rm, mkdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { test, expect, chromium, type BrowserContext, type Page } from '@playwright/test';

async function fillRange(page: Page, end = '07:00 AM') {
  await page.getByLabel('Start date', { exact: true }).fill('2026-11-03');
  await page.getByLabel('Start time', { exact: true }).fill('11:00 PM');
  await page.getByLabel('End date', { exact: true }).fill('2026-11-04');
  await page.getByLabel('End time', { exact: true }).fill(end);
}
async function hint(context: BrowserContext, page: Page, offline: boolean) {
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  await cdp.send('Network.overrideNetworkState', {
    offline,
    latency: 0,
    downloadThroughput: offline ? 0 : -1,
    uploadThroughput: offline ? 0 : -1,
  });
}

test('Ranger unavailability survives an offline browser restart and reconciles with Laravel', async ({
  baseURL,
}, testInfo) => {
  test.setTimeout(90_000);
  const profile = await mkdtemp(join(tmpdir(), 'rush-availability-'));
  let context: BrowserContext | undefined;
  const options = {
    baseURL: baseURL!,
    viewport: testInfo.project.use.viewport!,
    isMobile: testInfo.project.use.isMobile ?? false,
    timezoneId: 'America/Los_Angeles',
  };
  try {
    context = await chromium.launchPersistentContext(profile, options);
    let page = context.pages()[0]!;
    await page.goto('/sign-in');
    await page
      .getByLabel('Email', { exact: true })
      .fill(
        testInfo.project.name === 'mobile'
          ? 'robin.ranger@example.com'
          : 'jamie.ranger@example.com',
      );
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Ranger overview' })).toBeVisible();
    await page.goto('/availability');
    await expect(page.getByRole('heading', { name: 'Your unavailability' })).toBeVisible();
    await expect(page.getByText('Last sync:', { exact: false })).not.toContainText(
      'Not yet synchronized',
    );
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
    const initialCount = await page.getByTestId('availability-entry').count();
    await context.setOffline(true);
    await hint(context, page, true);
    await fillRange(page);
    await page.getByRole('button', { name: 'Save unavailability' }).click();
    await expect(page.getByTestId('availability-entry').filter({ hasText: 'Pending' })).toHaveCount(
      1,
    );
    await page.reload();
    await expect(page.getByTestId('availability-entry').filter({ hasText: 'Pending' })).toHaveCount(
      1,
    );
    await expect(page.getByText('Cached workspace.', { exact: false })).toBeVisible();
    await context.close();
    context = undefined;
    context = await chromium.launchPersistentContext(profile, { ...options, offline: true });
    page = context.pages()[0]!;
    await hint(context, page, true);
    await page.goto('/availability');
    await expect(page.getByTestId('availability-entry').filter({ hasText: 'Pending' })).toHaveCount(
      1,
    );
    await expect(
      page.getByTestId('availability-entry').filter({ hasText: 'Pending' }),
    ).toContainText('overnight');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    const evidence = resolve('../docs/evidence/RUSH-012');
    await mkdir(evidence, { recursive: true });
    await page.screenshot({
      path: join(evidence, testInfo.project.name + '-offline-availability.png'),
      fullPage: true,
    });
    await hint(context, page, false);
    await context.setOffline(false);
    // A real online navigation verifies the cookie session before resuming this partition.
    await page.reload();
    await expect(
      page.getByTestId('availability-entry').filter({ hasText: 'Synced — accepted by Server' }),
    ).toHaveCount(initialCount + 1);
    await expect(page.getByText('0 pending change(s).', { exact: false })).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('availability-entry')).toHaveCount(initialCount + 1);
    // Exercise editing and a genuine competing Server revision through the same API.
    await context.setOffline(true);
    await hint(context, page, true);
    const row = page.getByTestId('availability-entry').last();
    await row.getByRole('button', { name: 'Edit time range' }).click();
    await page.getByLabel('End time', { exact: true }).fill('08:00 AM');
    const session = (await (await context.request.get('/api/v1/session')).json()) as {
      memberships: { id: string; organization: { id: string } }[];
    };
    const membership = session.memberships[0]!;
    const cookies = await context.cookies();
    const csrf = decodeURIComponent(cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')!.value);
    const api = '/api/v1/organizations/' + membership.organization.id + '/sync';
    const headers = { 'X-XSRF-TOKEN': csrf, Origin: baseURL!, Accept: 'application/json' };
    const pull = await context.request.post(api + '/pull', { headers, data: {} });
    expect(pull.ok()).toBe(true);
    const body = (await pull.json()) as {
      changes: {
        record_type: string;
        value: {
          id: string;
          revision: number;
          starts_at: string;
          ends_at: string;
        } | null;
      }[];
    };
    const records = body.changes.filter(
      (change) => change.record_type === 'unavailability' && change.value,
    );
    const recordId = await row.getAttribute('data-record-id');
    const current = records.filter((change) => change.value?.id === recordId).at(-1)!.value!;
    await context.setOffline(true);
    await hint(context, page, true);
    await page.getByRole('button', { name: 'Save unavailability' }).click();
    await expect(page.getByTestId('availability-entry').filter({ hasText: 'Pending' })).toHaveCount(
      1,
    );
    const changed = await context.request.post(api + '/push', {
      headers,
      data: {
        operation_id: crypto.randomUUID(),
        type: 'unavailability.save',
        record_id: current.id,
        expected_revision: current.revision,
        payload: {
          membership_id: membership.id,
          starts_at: current.starts_at,
          ends_at: '2026-11-04T17:00:00Z',
        },
      },
    });
    expect(changed.ok()).toBe(true);
    expect(((await changed.json()) as { status: string }).status).toBe('accepted');
    await hint(context, page, false);
    await context.setOffline(false);
    await page.reload();
    await expect(
      page.getByTestId('availability-entry').filter({ hasText: 'Conflict — needs attention' }),
    ).toHaveCount(1);
    await expect(
      page.getByTestId('availability-entry').filter({ hasText: 'Conflict — needs attention' }),
    ).toContainText('8:00 AM');
    await page.reload();
    await expect(
      page.getByTestId('availability-entry').filter({ hasText: 'Conflict — needs attention' }),
    ).toHaveCount(1);
    await page.screenshot({
      path: join(evidence, testInfo.project.name + '-conflict-availability.png'),
      fullPage: true,
    });
  } finally {
    await context?.close();
    await rm(profile, { recursive: true, force: true });
  }
});

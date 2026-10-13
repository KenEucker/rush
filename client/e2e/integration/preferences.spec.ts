import { test, expect, type Page, type BrowserContext } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import { submitLogin } from '../fixtures/login';

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  expect((await submitLogin(page)).status()).toBe(200);
  await expect(page.getByRole('heading', { name: /overview/ })).toBeVisible();
}
async function headers(context: BrowserContext) {
  return {
    Accept: 'application/json',
    Origin: 'http://127.0.0.1:9187',
    'X-XSRF-TOKEN': decodeURIComponent(
      (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value,
    ),
  };
}
async function select(page: Page, label: string, option: string) {
  await page.getByLabel(label, { exact: true }).click();
  await page.getByRole('option', { name: option, exact: true }).click();
}

test('default and inherited weekly preferences survive offline reload and reconcile competing edits', async ({
  page,
  context,
  browser,
  baseURL,
}, info) => {
  test.setTimeout(150_000);
  const email =
    info.project.name === 'mobile' ? 'jordan.ranger@example.com' : 'taylor.ranger@example.com';
  const manager = await browser.newContext({ baseURL: baseURL! });
  const second = await browser.newContext({ baseURL: baseURL! });
  try {
    const admin = await manager.newPage();
    await signIn(admin, 'avery.management@example.com');
    const identity = (await (await manager.request.get('/api/v1/session')).json()) as {
      memberships: { organization: { id: string } }[];
    };
    const base = `/api/v1/organizations/${identity.memberships[0]!.organization.id}`;
    const seasonId = randomUUID();
    const seasonName = `Preferences ${info.project.name} ${seasonId.slice(0, 6)}`;
    const seasonResponse = await manager.request.put(`${base}/seasons/${seasonId}`, {
      headers: await headers(manager),
      data: {
        name: seasonName,
        starts_on: '2026-10-01',
        ends_on: '2026-12-31',
        timezone: 'America/Los_Angeles',
        week_starts_on: 1,
        expected_revision: null,
        phases: [{ name: 'Fall', starts_on: '2026-10-01', ends_on: '2026-12-31' }],
      },
    });
    expect(seasonResponse.ok()).toBe(true);
    const season = (await seasonResponse.json()) as { phases: { id: string }[] };
    const coverage = await manager.request.put(`${base}/phases/${season.phases[0]!.id}/coverage`, {
      headers: await headers(manager),
      data: {
        expected_revision: null,
        expected_season_revision: 1,
        model: 'dedicated',
        shifts: [{ name: 'Evening preference test', start_time: '17:00', duration_minutes: 480 }],
        areas: [{ name: 'Park' }],
        groups: [],
        staffing: [{ shift_name: 'Evening preference test', area_name: 'Park', positions: 1 }],
        reason: 'Preference fixture',
      },
    });
    expect(coverage.ok()).toBe(true);
    await signIn(page, email);
    const peerPage = await second.newPage();
    await signIn(peerPage, email);
    await page.goto('/preferences');
    await expect(page.getByRole('heading', { name: 'Scheduling preferences' })).toBeVisible();
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
    await select(page, 'Desired weekly hours', 'Set desired hours');
    await page.getByLabel('Desired hours', { exact: true }).fill('32');
    await select(page, 'Days off preference', 'Consecutive days off');
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    const defaultRow = page
      .getByTestId('preference-entry')
      .filter({ hasText: 'Default preferences' });
    await expect(defaultRow).toContainText('Synced — accepted by Server');
    await select(page, 'Profile', 'One week');
    await select(page, 'Season', seasonName);
    await page.getByLabel('Date in the week (YYYY-MM-DD)', { exact: true }).fill('2026-11-01');
    await expect(page.getByTestId('effective-preferences')).toContainText('Desired hours: 32');
    await context.setOffline(true);
    await page.getByLabel('Use default preferred shifts', { exact: true }).uncheck();
    await page.getByLabel('Preferred shifts', { exact: true }).click();
    await page
      .getByRole('option', { name: 'Fall · Evening preference test · 5:00 PM', exact: true })
      .click();
    await page.keyboard.press('Escape');
    await select(page, 'Days off preference', 'Separated days off');
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    const weekRow = page
      .getByTestId('preference-entry')
      .filter({ hasText: 'Week of Mon, Oct 26, 2026' });
    await expect(weekRow).toContainText('Pending — saved on this device');
    await page.reload();
    await expect(weekRow).toContainText('Days off: separated');
    await mkdir('../docs/evidence/RUSH-019', { recursive: true });
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({
      path: `../docs/evidence/RUSH-019/${info.project.name}-offline.png`,
      fullPage: true,
    });
    await context.setOffline(false);
    await page.reload();
    await expect(weekRow).toContainText('Synced — accepted by Server');
    await weekRow.getByRole('button', { name: 'Edit preferences' }).click();
    await expect(page.getByTestId('effective-preferences')).toContainText('Desired hours: 32');
    await page.getByLabel('Date in the week (YYYY-MM-DD)', { exact: true }).fill('2026-11-02');
    await expect(page.getByTestId('effective-preferences')).toContainText('Days off: consecutive');
    await defaultRow.getByRole('button', { name: 'Edit preferences' }).click();
    await context.setOffline(true);
    await page.getByLabel('Desired hours', { exact: true }).fill('24');
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await expect(defaultRow).toContainText('Pending — saved on this device');
    const pull = await second.request.post(`${base}/sync/pull`, {
      headers: await headers(second),
      data: {},
    });
    const snapshot = (await pull.json()) as {
      changes: {
        record_type: string;
        value: {
          id: string;
          membership_id: string;
          season_id: string | null;
          revision: number;
        } & Record<string, unknown>;
      }[];
    };
    const current = snapshot.changes
      .filter((c) => c.record_type === 'preference_profile' && c.value.season_id === null)
      .at(-1)!.value;
    const payload = Object.fromEntries(
      [
        'membership_id',
        'season_id',
        'week_starts_on',
        'preferred_shift_ids',
        'hours_mode',
        'desired_weekly_minutes',
        'days_off',
      ].map((k) => [k, current[k]]),
    );
    const response = await second.request.post(`${base}/sync/push`, {
      headers: await headers(second),
      data: {
        operation_id: randomUUID(),
        type: 'preference_profile.save',
        record_id: current.id,
        expected_revision: current.revision,
        payload: { ...payload, desired_weekly_minutes: 2400 },
      },
    });
    expect(((await response.json()) as { status: string }).status).toBe('accepted');
    await context.setOffline(false);
    await page.reload();
    await expect(defaultRow).toContainText('Conflict — needs attention');
    await expect(defaultRow).toContainText('Hours: 24');
    await expect(defaultRow).toContainText('Hours: 40');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({
      path: `../docs/evidence/RUSH-019/${info.project.name}-conflict.png`,
      fullPage: true,
    });
    await defaultRow.getByRole('button', { name: 'Review saved intent' }).click();
    await expect(page.getByLabel('Desired hours', { exact: true })).toHaveValue('24');
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await expect(defaultRow).toContainText('Synced — accepted by Server');
    await expect(defaultRow).toContainText('Hours: 24');
    await weekRow.getByRole('button', { name: 'Edit preferences' }).click();
    await expect(page.getByTestId('effective-preferences')).toContainText('Desired hours: 24');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({
      path: `../docs/evidence/RUSH-019/${info.project.name}-preferences.png`,
      fullPage: true,
    });
  } finally {
    await manager.close();
    await second.close();
  }
});

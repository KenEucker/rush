import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import type { SessionIdentity } from '../../src/data/api/session';
import { withLoginCooldown } from '../fixtures/login';

test('Management configures hour policies and desirability overrides with retained validation and stale input', async ({
  page,
  context,
}) => {
  test.setTimeout(120_000);
  await context.request.get('/sanctum/csrf-cookie');
  const headers = {
    Accept: 'application/json',
    'X-XSRF-TOKEN': decodeURIComponent(
      (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')!.value,
    ),
  };
  expect(
    (
      await withLoginCooldown(() =>
        context.request.post('/login', {
          headers,
          data: { email: 'riley.management@example.com', password: 'password' },
        }),
      )
    ).status(),
  ).toBe(200);
  headers['X-XSRF-TOKEN'] = decodeURIComponent(
    (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')!.value,
  );
  const identity = (await (await context.request.get('/api/v1/session')).json()) as SessionIdentity;
  const organization = identity.memberships[0]!.organization.id;
  const seasonId = randomUUID();
  const season = await context.request.put(
    `/api/v1/organizations/${organization}/seasons/${seasonId}`,
    {
      headers,
      data: {
        name: 'Policy proof ' + test.info().project.name,
        starts_on: '2026-01-01',
        ends_on: '2026-12-31',
        timezone: 'America/Los_Angeles',
        week_starts_on: 1,
        expected_revision: null,
        phases: [{ name: 'Patrol phase', starts_on: '2026-01-01', ends_on: '2026-12-31' }],
      },
    },
  );
  expect(season.status()).toBe(201);
  const phase = ((await season.json()) as { phases: { id: string }[] }).phases[0]!.id;
  const base = `/api/v1/organizations/${organization}/phases/${phase}`;
  const coverage = await context.request.put(`${base}/coverage`, {
    headers,
    data: {
      expected_revision: null,
      expected_season_revision: 1,
      model: 'dedicated',
      reason: 'Configure overnight shift',
      shifts: [{ name: 'Night patrol', start_time: '22:00', duration_minutes: 480 }],
      areas: [{ name: 'North' }],
      groups: [],
      staffing: [],
    },
  });
  expect(coverage.status()).toBe(200);
  await page.goto(`/admin/organizations/${organization}/seasons/${seasonId}`);
  await page.locator('#post-form').locator(`a[href$="/phases/${phase}/policies"]`).click();
  await expect(page.getByText('Policies need configuration or review.').first()).toBeVisible();
  await page.getByLabel('Weekly scheduling target (minutes)', { exact: false }).fill('1920');
  await page.getByLabel('Weekly planned cap (minutes)', { exact: false }).fill('1440');
  await page.getByLabel('Planned hours warning threshold (minutes)', { exact: false }).fill('2100');
  await page.getByLabel('Actual hours warning threshold (minutes)', { exact: false }).fill('2400');
  await page.getByLabel('Baseline classification', { exact: true }).selectOption('neutral');
  await page.getByRole('link', { name: 'Add weekday override', exact: true }).click();
  await page.getByLabel('Override weekday', { exact: true }).selectOption('1');
  await page.getByLabel('Weekday classification', { exact: true }).selectOption('undesirable');
  await page.getByRole('link', { name: 'Add dated override', exact: true }).click();
  await page.getByLabel('Override date', { exact: true }).fill('2026-01-05');
  await page.getByLabel('Dated classification', { exact: true }).selectOption('desirable');
  await page
    .getByLabel('Reason for policy change', { exact: false })
    .fill('Weekly inputs and Monday exception');
  await page.getByRole('button', { name: 'Save scheduling policies', exact: true }).click();
  await expect(page.getByText(/must be greater than or equal/).first()).toBeVisible();
  await expect(page.getByLabel('Override date', { exact: true })).toHaveValue('2026-01-05');
  await page.getByLabel('Weekly planned cap (minutes)', { exact: false }).fill('2400');
  await page.getByRole('button', { name: 'Save scheduling policies', exact: true }).click();
  await expect(page.getByText('Scheduling policies saved.').first()).toBeVisible();
  await page.reload();
  await expect(page.getByLabel('Weekday classification', { exact: true })).toHaveValue(
    'undesirable',
  );
  await expect(page.getByLabel('Dated classification', { exact: true })).toHaveValue('desirable');
  const stale = await context.newPage();
  await stale.goto(page.url());
  await page.getByLabel('Weekly scheduling target (minutes)', { exact: false }).fill('1440');
  await page.getByLabel('Reason for policy change', { exact: false }).fill('Lower planned target');
  await page.getByRole('button', { name: 'Save scheduling policies', exact: true }).click();
  await expect(page.locator('input[name="configuration[expected_revision]"]')).toHaveValue('2');
  await stale.getByLabel('Weekly scheduling target (minutes)', { exact: false }).fill('1800');
  await stale.getByLabel('Reason for policy change', { exact: false }).fill('Keep competing input');
  await stale.getByRole('button', { name: 'Save scheduling policies', exact: true }).click();
  await expect(stale.getByText(/Another manager changed these policies/).first()).toBeVisible();
  await expect(
    stale.getByLabel('Weekly scheduling target (minutes)', { exact: false }),
  ).toHaveValue('1800');
  await stale.close();
  const response = await context.request.get(`${base}/policies`);
  expect(response.headers()['cache-control']).toContain('no-store');
  const saved = (await response.json()) as {
    revision: number;
    weekly_target_minutes: number;
    date_overrides: unknown[];
  };
  expect(saved.revision).toBe(2);
  expect(saved.weekly_target_minutes).toBe(1440);
  expect(saved.date_overrides).toHaveLength(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  );
  await mkdir('../docs/evidence/RUSH-017', { recursive: true });
  const screenshot = await page.screenshot({
    path: `../docs/evidence/RUSH-017/${test.info().project.name}-policies.png`,
    fullPage: true,
  });
  await test.info().attach('phase-policies', { body: screenshot, contentType: 'image/png' });
});

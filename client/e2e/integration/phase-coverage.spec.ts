import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import type { SessionIdentity } from '../../src/data/api/session';

test('Management configures dedicated and shared phase coverage with validation and stale recovery', async ({
  page,
  context,
}) => {
  test.setTimeout(120_000);
  await context.request.get('/sanctum/csrf-cookie');
  const token = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')!;
  const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token.value) };
  const signIn = () =>
    context.request.post('/login', {
      headers,
      data: { email: 'riley.management@example.com', password: 'password' },
    });
  let login = await signIn();
  if (login.status() === 429) {
    const retry = Number(login.headers()['retry-after']);
    expect(retry).toBeGreaterThan(0);
    expect(retry).toBeLessThanOrEqual(60);
    await new Promise((resolve) => setTimeout(resolve, (retry + 1) * 1000));
    login = await signIn();
  }
  expect(login.status()).toBe(200);
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
        name: 'Coverage proof ' + test.info().project.name,
        starts_on: '2026-01-01',
        ends_on: '2026-12-31',
        timezone: 'America/Los_Angeles',
        week_starts_on: 1,
        expected_revision: null,
        phases: [
          { name: 'Low staffing', starts_on: '2026-01-01', ends_on: '2026-05-31' },
          { name: 'Full staffing', starts_on: '2026-06-01', ends_on: '2026-12-31' },
        ],
      },
    },
  );
  expect(season.status()).toBe(201);
  const phases = ((await season.json()) as { phases: { id: string }[] }).phases;
  await page.goto(`/admin/organizations/${organization}/seasons/${seasonId}`);
  await page.locator(`a[href$="/phases/${phases[0]!.id}/coverage"]`).click();
  await page.locator('select[name="configuration[model]"]').selectOption('dedicated');
  await page.getByRole('link', { name: 'Add shift', exact: true }).click();
  await page.getByLabel('Shift name', { exact: true }).fill('Evening');
  await page.getByLabel('Shift local start', { exact: true }).fill('21:30');
  await page.getByLabel('Shift duration in minutes', { exact: true }).fill('390');
  for (const name of ['North', 'South']) {
    await page.getByRole('link', { name: 'Add area', exact: true }).click();
    await page.getByLabel('Area name', { exact: true }).last().fill(name);
  }
  await page.getByRole('link', { name: 'Add staffing requirement', exact: true }).click();
  await page.getByLabel('Staffing shift name', { exact: true }).fill('Evening');
  await page.getByLabel('Staffing dedicated area', { exact: true }).fill('North');
  await page.getByLabel('Staffing shared group', { exact: true }).fill('Invalid mixed model');
  await page.getByLabel('Required positions', { exact: true }).fill('2');
  await page
    .getByLabel('Reason for coverage change', { exact: false })
    .fill('Limited evening patrol');
  await page.getByRole('button', { name: 'Save phase coverage', exact: true }).click();
  await expect(page.getByText(/coverage models cannot be mixed/).first()).toBeVisible();
  await expect(page.getByLabel('Shift local start', { exact: true })).toHaveValue('21:30');
  await page.getByLabel('Staffing shared group', { exact: true }).clear();
  await page.getByRole('button', { name: 'Save phase coverage', exact: true }).click();
  await expect(page.getByText('Phase coverage saved.').first()).toBeVisible();
  await expect(page.locator('input[name="configuration[expected_revision]"]')).toHaveValue('1');
  await page.reload();
  await expect(page.getByLabel('Shift duration in minutes', { exact: true })).toHaveValue('390');

  const stale = await context.newPage();
  await stale.goto(page.url());
  await page.locator('select[name="configuration[model]"]').selectOption('shared');
  await page.getByRole('link', { name: 'Add shared group', exact: true }).click();
  await page.getByLabel('Group name', { exact: true }).fill('Joint patrol');
  await page.getByLabel('Group area names', { exact: true }).fill('North, South');
  await page.getByLabel('Staffing dedicated area', { exact: true }).clear();
  await page.getByLabel('Staffing shared group', { exact: true }).fill('Joint patrol');
  await page.getByLabel('Required positions', { exact: true }).fill('1');
  await page
    .getByLabel('Reason for coverage change', { exact: false })
    .fill('One Ranger covers both areas');
  await page.getByRole('button', { name: 'Save phase coverage', exact: true }).click();
  await expect(page.locator('input[name="configuration[expected_revision]"]')).toHaveValue('2');
  await stale.getByLabel('Shift name', { exact: true }).fill('Keep this unsaved shift');
  await stale.getByLabel('Reason for coverage change', { exact: false }).fill('Competing edit');
  await stale.getByRole('button', { name: 'Save phase coverage', exact: true }).click();
  await expect(
    stale.getByText(/Another manager changed this coverage or season/).first(),
  ).toBeVisible();
  await expect(stale.getByLabel('Shift name', { exact: true })).toHaveValue(
    'Keep this unsaved shift',
  );
  await stale.close();
  const response = await context.request.get(
    `/api/v1/organizations/${organization}/phases/${phases[0]!.id}/coverage`,
  );
  expect(response.headers()['cache-control']).toContain('no-store');
  const saved = (await response.json()) as {
    model: string;
    groups: { area_names: string[] }[];
    revision: number;
  };
  expect(saved.model).toBe('shared');
  expect(saved.revision).toBe(2);
  expect(saved.groups[0]!.area_names).toEqual(['North', 'South']);
  await page.reload();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  );
  await mkdir('../docs/evidence/RUSH-016', { recursive: true });
  const screenshot = await page.screenshot({
    path: `../docs/evidence/RUSH-016/${test.info().project.name}-coverage.png`,
    fullPage: true,
  });
  await test.info().attach('phase-coverage', { body: screenshot, contentType: 'image/png' });
  // The other phase has its own inputs; nothing is silently inherited.
  const untouched = await context.request.get(
    `/api/v1/organizations/${organization}/phases/${phases[1]!.id}/coverage`,
  );
  expect(((await untouched.json()) as { revision: number | null }).revision).toBeNull();
});

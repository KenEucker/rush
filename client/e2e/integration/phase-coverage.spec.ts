import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import type { SessionIdentity } from '../../src/data/api/session';
import { withLoginCooldown } from '../fixtures/login';

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
  const login = await withLoginCooldown(signIn);
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
  const selector = (label: string, index = 0) =>
    page.locator(`select[aria-label="${label}"]`).nth(index).locator('..');
  const choose = async (label: string, name: string, index = 0) => {
    const field = selector(label, index);
    if (!(await field.locator('.ts-dropdown').isVisible())) {
      // Target the search input in a multi-select; its center can contain a pill's remove button.
      const multiple = await field.locator('select').getAttribute('multiple');
      await field.locator(multiple === null ? '.ts-control' : '.ts-control input').click();
    }
    await field.locator('.ts-dropdown').getByText(name, { exact: true }).click();
  };
  await page.goto(`/admin/organizations/${organization}/seasons/${seasonId}`);
  await page.locator(`a[href$="/phases/${phases[0]!.id}/coverage"]`).click();
  await expect(page).toHaveURL(new RegExp(`/phases/${phases[0]!.id}/coverage$`));
  await expect(page.locator('select[name="configuration[model]"]')).toHaveCount(0);
  await page.getByRole('link', { name: 'Add staffing requirement', exact: true }).click();
  await expect(selector('Staffing shift name').locator('select')).toHaveValue('');
  await expect(selector('Staffing dedicated area').locator('select')).toHaveValue('');
  await expect(selector('Staffing shared group').locator('select')).toHaveValue('');
  await page.getByRole('link', { name: 'Add shift', exact: true }).click();
  await page.getByLabel('Shift name', { exact: true }).fill('Evening');
  await page.getByLabel('Shift local start', { exact: true }).fill('21:30');
  await page.getByLabel('Shift duration in minutes', { exact: true }).fill('390');
  for (const name of ['North', 'South']) {
    await page.getByRole('link', { name: 'Add area', exact: true }).click();
    await page.getByLabel('Area name', { exact: true }).last().fill(name);
  }
  await choose('Staffing shift name', 'Evening');
  await page.getByLabel('Shift name', { exact: true }).fill('Night');
  await expect(selector('Staffing shift name').locator('.item')).toHaveText(
    'Evening (not defined)',
  );
  await choose('Staffing shift name', 'Night');
  await page.getByLabel('Shift name', { exact: true }).fill('Evening');
  await choose('Staffing shift name', 'Evening');
  await choose('Staffing dedicated area', 'North');
  await page.getByLabel('Area name', { exact: true }).first().fill('Northern area');
  await expect(selector('Staffing dedicated area').locator('.item')).toHaveText(
    'North (not defined)',
  );
  await choose('Staffing dedicated area', 'Northern area');
  await page.getByLabel('Area name', { exact: true }).first().fill('North');
  await choose('Staffing dedicated area', 'North');
  await page.getByRole('link', { name: 'Add shared group', exact: true }).click();
  const temporaryGroup = page.getByLabel('Group name', { exact: true });
  await temporaryGroup.fill('Temporary group');
  await choose('Group area names', 'North');
  await choose('Staffing shared group', 'Temporary group');
  await temporaryGroup
    .locator('xpath=ancestor::tr')
    .getByRole('link', { name: 'Remove row' })
    .click();
  await expect(selector('Staffing shared group').locator('.item')).toHaveText(
    'Temporary group (not defined)',
  );
  await page.getByLabel('Required positions', { exact: true }).fill('2');
  await page
    .getByLabel('Reason for coverage change', { exact: false })
    .fill('Limited evening patrol');
  await page.getByRole('button', { name: 'Save phase coverage', exact: true }).click();
  await expect(
    page
      .getByText('Choose exactly one dedicated area or shared group for each staffing row.')
      .first(),
  ).toBeVisible();
  await expect(page.getByLabel('Shift local start', { exact: true })).toHaveValue('21:30');
  await expect(selector('Staffing shift name').locator('select')).toHaveValue('Evening');
  await expect(selector('Staffing dedicated area').locator('select')).toHaveValue('North');
  await expect(selector('Staffing shared group').locator('select')).toHaveValue('Temporary group');
  await choose('Staffing shared group', 'No shared group');
  await page.getByRole('button', { name: 'Save phase coverage', exact: true }).click();
  await expect(page.getByText('Phase coverage saved.').first()).toBeVisible();
  await expect(page.locator('input[name="configuration[expected_revision]"]')).toHaveValue('1');
  await page.reload();
  await expect(page.getByLabel('Shift duration in minutes', { exact: true })).toHaveValue('390');
  await expect(selector('Staffing dedicated area').locator('select')).toHaveValue('North');
  await expect(selector('Staffing shared group').locator('select')).toHaveValue('');

  const stale = await context.newPage();
  await stale.goto(page.url());
  await page.getByRole('link', { name: 'Add shared group', exact: true }).click();
  await page.getByLabel('Group name', { exact: true }).fill('Joint patrol');
  await choose('Group area names', 'North');
  await choose('Group area names', 'South');
  const members = selector('Group area names');
  await members.locator('.item[data-value="South"] .remove').click();
  await expect(members.locator('select')).toHaveValues(['North']);
  await choose('Group area names', 'South');
  // New unsaved definitions are selectable immediately, and removed references retain intent.
  await page.getByRole('link', { name: 'Add area', exact: true }).click();
  const newArea = page.getByLabel('Area name', { exact: true }).last();
  await newArea.fill('East');
  await choose('Group area names', 'East');
  await newArea.locator('xpath=ancestor::tr').getByRole('link', { name: 'Remove row' }).click();
  await expect(members.locator('.item[data-value="East"]')).toContainText('East (not defined)');
  await choose('Staffing dedicated area', 'No dedicated area');
  await choose('Staffing shared group', 'Joint patrol');
  await page.getByLabel('Group name', { exact: true }).fill('Combined patrol');
  await expect(selector('Staffing shared group').locator('.item')).toHaveText(
    'Joint patrol (not defined)',
  );
  await choose('Staffing shared group', 'Combined patrol');
  await page.getByLabel('Group name', { exact: true }).fill('Joint patrol');
  await choose('Staffing shared group', 'Joint patrol');
  await page.getByLabel('Required positions', { exact: true }).fill('1');
  await page
    .getByLabel('Reason for coverage change', { exact: false })
    .fill('One Ranger covers both areas');
  await page.getByRole('button', { name: 'Save phase coverage', exact: true }).click();
  await expect(page.getByText('Choose areas defined in this phase.').first()).toBeVisible();
  await expect(members.locator('select')).toHaveValues(['North', 'South', 'East']);
  await expect(selector('Staffing dedicated area').locator('select')).toHaveValue('');
  await expect(selector('Staffing shared group').locator('select')).toHaveValue('Joint patrol');
  await members.locator('.item[data-value="East"] .remove').click();
  // Keep the shared row and add dedicated staffing in the same phase and shift.
  await page.getByRole('link', { name: 'Add staffing requirement', exact: true }).click();
  await choose('Staffing shift name', 'Evening', 1);
  await choose('Staffing dedicated area', 'South', 1);
  await page.getByLabel('Required positions', { exact: true }).nth(1).fill('2');
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
    staffing: {
      shift_name: string;
      area_name: string | null;
      group_name: string | null;
      positions: number;
    }[];
    revision: number;
  };
  expect(saved.model).toBe('mixed');
  expect(saved.staffing).toEqual(
    expect.arrayContaining([
      { shift_name: 'Evening', area_name: null, group_name: 'Joint patrol', positions: 1 },
      { shift_name: 'Evening', area_name: 'South', group_name: null, positions: 2 },
    ]),
  );
  expect(saved.staffing).toHaveLength(2);
  expect(saved.revision).toBe(2);
  expect(saved.groups[0]!.area_names).toEqual(['North', 'South']);
  await page.reload();
  await expect(members.locator('select')).toHaveValues(['North', 'South']);
  // Stored UUID ordering can reorder staffing rows; inspect targets without relying on order.
  await expect(page.locator('select[aria-label="Staffing shift name"]')).toHaveCount(2);
  expect(
    await page
      .locator('select[aria-label="Staffing dedicated area"]')
      .evaluateAll((fields) => fields.map((field) => (field as HTMLSelectElement).value).sort()),
  ).toEqual(['', 'South']);
  expect(
    await page
      .locator('select[aria-label="Staffing shared group"]')
      .evaluateAll((fields) => fields.map((field) => (field as HTMLSelectElement).value).sort()),
  ).toEqual(['', 'Joint patrol']);
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

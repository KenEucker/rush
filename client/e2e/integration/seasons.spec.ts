import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import type { SessionIdentity } from '../../src/data/api/session';

test.use({ timezoneId: 'America/Los_Angeles' });

test.beforeEach(async ({ context }) => {
  test.setTimeout(90_000);
  await context.request.get('/sanctum/csrf-cookie');
  const cookie = (await context.cookies()).find((item) => item.name === 'XSRF-TOKEN');
  expect(cookie).toBeDefined();
  const signIn = () =>
    context.request.post('/login', {
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie!.value) },
      data: { email: 'riley.management@example.com', password: 'password' },
    });
  let login = await signIn();
  // All integration tests share the real 30/minute IP limiter. Respect its
  // cooldown during fixture login; never disable or raise application limits.
  if (login.status() === 429) {
    const retryAfter = Number(login.headers()['retry-after']);
    expect(retryAfter).toBeGreaterThan(0);
    expect(retryAfter).toBeLessThanOrEqual(60);
    await new Promise((resolve) => setTimeout(resolve, (retryAfter + 1) * 1000));
    login = await signIn();
  }
  expect(login.status()).toBe(200);
});

test('Management configures seasons and phases with validation and stale-edit recovery', async ({
  page,
  context,
}) => {
  const identity = (await (await context.request.get('/api/v1/session')).json()) as SessionIdentity;
  const organization = identity.memberships[0]!.organization.id;
  await page.goto('/admin/main');
  await page.getByRole('link', { name: /Seasons and phases —/ }).click();
  await page
    .getByLabel('Season name', { exact: false })
    .fill('Calendar proof ' + test.info().project.name);
  await page.getByLabel('Season starts on', { exact: false }).fill('2026-03-01');
  await page.getByLabel('Season ends on (inclusive)', { exact: false }).fill('2026-11-30');
  await page.locator('select[name="configuration[timezone]"]').selectOption('America/Los_Angeles');
  await page.locator('select[name="configuration[week_starts_on]"]').selectOption('1');
  for (const [name, start, end] of [
    ['Low staffing', '2026-03-01', '2026-05-31'],
    ['Full staffing', '2026-05-31', '2026-11-30'],
  ]) {
    await page.getByRole('link', { name: 'Add phase', exact: true }).click();
    await expect(page.getByLabel('Phase starts on', { exact: true }).last()).toHaveValue(
      name === 'Low staffing' ? '2026-03-01' : '2026-06-01',
    );
    await page.getByRole('textbox', { name: 'Phase name', exact: true }).last().fill(name!);
    await page.getByLabel('Phase starts on', { exact: true }).last().fill(start!);
    await page.getByLabel('Phase ends on', { exact: true }).last().fill(end!);
  }
  await page.getByLabel('Reason for change', { exact: false }).fill('Configure season calendar');
  await page.getByRole('button', { name: 'Save season and phases', exact: true }).click();
  await expect(
    page.getByText('Phase dates cannot overlap (end dates are inclusive).').first(),
  ).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Phase name', exact: true }).first()).toHaveValue(
    'Low staffing',
  );
  await page.getByLabel('Phase starts on', { exact: true }).last().fill('2026-06-01');
  await page.getByRole('button', { name: 'Save season and phases', exact: true }).click();
  await expect(page).toHaveURL(/\/seasons\/[0-9a-f-]{36}$/);
  await expect(page.getByText('Season and phases saved.').first()).toBeVisible();

  const stale = await context.newPage();
  await stale.goto(page.url());
  await page.getByLabel('Phase ends on', { exact: true }).first().fill('2026-06-14');
  await page.getByLabel('Phase starts on', { exact: true }).last().fill('2026-06-15');
  await page
    .getByLabel('Reason for change', { exact: false })
    .fill('Move phase transition together');
  await page.getByRole('button', { name: 'Save season and phases', exact: true }).click();
  await expect(page.locator('input[name="configuration[expected_revision]"]')).toHaveValue('2');
  await stale.getByLabel('Season name', { exact: false }).fill('Preserve this unsaved name');
  await stale.getByLabel('Reason for change', { exact: false }).fill('Competing tab');
  await stale.getByRole('button', { name: 'Save season and phases', exact: true }).click();
  await expect(stale.getByText(/Another manager saved this season/).first()).toBeVisible();
  await expect(stale.getByLabel('Season name', { exact: false })).toHaveValue(
    'Preserve this unsaved name',
  );
  await stale.close();
  await page.reload();
  await expect(page.getByLabel('Phase starts on', { exact: true }).last()).toHaveValue(
    '2026-06-15',
  );
  const id = page.url().split('/').at(-1)!;
  const response = await context.request.get(
    '/api/v1/organizations/' + organization + '/seasons/' + id,
  );
  expect(response.status()).toBe(200);
  expect(((await response.json()) as { revision: number }).revision).toBe(2);
  expect(response.headers()['cache-control']).toContain('no-store');
  await mkdir('../docs/evidence/RUSH-015', { recursive: true });
  await page.evaluate(() => window.scrollTo(0, 0));
  const screenshot = await page.screenshot({
    path: '../docs/evidence/RUSH-015/' + test.info().project.name + '-season.png',
    fullPage: true,
  });
  await test.info().attach('season-configuration', { body: screenshot, contentType: 'image/png' });
  // Defaults also work when extending an existing season after save/reload.
  await page.getByLabel('Phase ends on', { exact: true }).last().fill('2026-11-28');
  await page.getByRole('link', { name: 'Add phase', exact: true }).click();
  await expect(page.getByLabel('Phase starts on', { exact: true }).last()).toHaveValue(
    '2026-11-29',
  );
  await expect(page.getByLabel('Phase starts on', { exact: true }).nth(1)).toHaveValue(
    '2026-06-15',
  );
});

test('New phase dates follow calendar boundaries without changing existing input', async ({
  page,
}) => {
  await page.goto('/admin/main');
  await page.getByRole('link', { name: /Seasons and phases —/ }).click();
  const seasonStart = page.getByLabel('Season starts on', { exact: false });
  const seasonEnd = page.getByLabel('Season ends on (inclusive)', { exact: false });
  const starts = page.getByLabel('Phase starts on', { exact: true });
  const ends = page.getByLabel('Phase ends on', { exact: true });
  const add = page.getByRole('link', { name: 'Add phase', exact: true });
  const remove = page.getByRole('link', { name: 'Remove row', exact: true });

  await add.click();
  await expect(starts.last()).toHaveValue('');
  await remove.last().click();
  await seasonStart.fill('2028-02-01');
  await seasonEnd.fill('2029-01-31');
  await add.click();
  await expect(starts.last()).toHaveValue('2028-02-01');
  await starts.first().fill('2028-02-03');
  await ends.first().fill('2028-02-28');
  await add.click();
  await expect(starts.last()).toHaveValue('2028-02-29');
  await ends.last().fill('2028-03-01');
  await add.click();
  await expect(starts.last()).toHaveValue('2028-03-02');

  // A missing preceding end must not fall back to the season start and overlap it.
  await add.click();
  await expect(starts.last()).toHaveValue('');
  await remove.last().click();
  await ends.last().fill('2028-12-31');
  await add.click();
  await expect(starts.last()).toHaveValue('2029-01-01');

  await ends.last().fill('2029-01-31');
  await add.click();
  await expect(starts.last()).toHaveValue('');
  await remove.last().click();
  // The preceding row is the current last row even after deletion.
  await remove.last().click();
  await ends.last().fill('2028-03-12');
  await add.click();
  await expect(starts.last()).toHaveValue('2028-03-13');
  await seasonStart.fill('2028-02-02');
  await ends.first().fill('2028-02-27');
  await expect(starts.first()).toHaveValue('2028-02-03');
  await expect(starts.nth(1)).toHaveValue('2028-02-29');
  await ends.last().fill('2028-01-30');
  await add.click();
  await expect(starts.last()).toHaveValue('');
});

test('End-date pickers begin after their start and enforce the minimum without overwriting edits', async ({
  page,
}) => {
  await page.goto('/admin/main');
  await page.getByRole('link', { name: /Seasons and phases —/ }).click();
  const seasonStart = page.getByLabel('Season starts on', { exact: false });
  const seasonEnd = page.getByLabel('Season ends on (inclusive)', { exact: false });
  const starts = page.getByLabel('Phase starts on', { exact: true });
  const ends = page.getByLabel('Phase ends on', { exact: true });
  const add = page.getByRole('link', { name: 'Add phase', exact: true });

  await seasonEnd.focus();
  await expect(seasonEnd).toHaveValue('');
  await seasonStart.fill('2028-02-28');
  await seasonEnd.focus();
  await expect(seasonEnd).toHaveValue('2028-02-29');
  await seasonEnd.fill('2028-02-28');
  expect(await seasonEnd.evaluate((input: HTMLInputElement) => input.validity.rangeUnderflow)).toBe(
    true,
  );
  await seasonEnd.fill('2028-12-31');

  await add.click();
  await ends.last().focus();
  await expect(ends.last()).toHaveValue('2028-02-29');
  await starts.last().fill('2028-03-12');
  await expect(ends.last()).toHaveValue('2028-02-29');
  await expect(ends.last()).toHaveAttribute('min', '2028-03-13');
  expect(
    await ends.last().evaluate((input: HTMLInputElement) => input.validity.rangeUnderflow),
  ).toBe(true);
  await ends.last().focus();
  await ends.last().fill('');
  await expect(ends.last()).toHaveValue('');
  await starts.last().focus();
  await ends.last().focus();
  await expect(ends.last()).toHaveValue('2028-03-13');
  await ends.last().fill('2028-04-30');
  await starts.last().fill('2028-03-14');
  await ends.last().focus();
  await expect(ends.last()).toHaveValue('2028-04-30');

  // There must be room for both the new start and its next-day minimum end.
  await ends.last().fill('2028-12-30');
  await add.click();
  await expect(starts.last()).toHaveValue('');
  await starts.last().fill('2028-12-31');
  await ends.last().focus();
  await expect(ends.last()).toHaveValue('');
  await expect(ends.last()).toHaveAttribute('min', '2029-01-01');
  await expect(ends.last()).toHaveAttribute('max', '2028-12-31');

  await seasonStart.fill('2028-12-31');
  await seasonEnd.focus();
  await seasonEnd.fill('');
  await expect(seasonEnd).toHaveValue('');
  await seasonStart.focus();
  await seasonEnd.focus();
  await expect(seasonEnd).toHaveValue('2029-01-01');
  await seasonStart.fill('');
  await expect(seasonEnd).toHaveAttribute('min', '');
  await expect(seasonEnd).toHaveValue('2029-01-01');
});

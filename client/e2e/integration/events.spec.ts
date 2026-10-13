import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import type { SessionIdentity } from '../../src/data/api/session';
import { withLoginCooldown } from '../fixtures/login';

test.use({ actionTimeout: 15_000 });

test('Management configures a one-off paid meeting, reviews staffing and retains stale edits', async ({
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
  const season = randomUUID();
  const base = `/api/v1/organizations/${organization}/seasons/${season}`;
  expect(
    (
      await context.request.put(base, {
        headers,
        data: {
          name: 'Event proof ' + test.info().project.name,
          starts_on: '2026-01-01',
          ends_on: '2026-12-31',
          timezone: 'America/Los_Angeles',
          week_starts_on: 1,
          expected_revision: null,
          phases: [],
        },
      })
    ).status(),
  ).toBe(201);
  await page.goto('/admin/main');
  await expect(
    page.getByRole('heading', { name: 'Events and attendance', exact: true }),
  ).toBeVisible();
  await mkdir('../docs/evidence/RUSH-020', { recursive: true });
  await page.screenshot({
    path: `../docs/evidence/RUSH-020/events-navigation-${test.info().project.name}.png`,
    fullPage: true,
  });
  await page.locator(`a[href$="/seasons/${season}/events/new"]`).click();
  await page.getByLabel('Event name', { exact: false }).fill('Monday Meeting');
  await page
    .getByLabel('Event starts (season time zone)', { exact: false })
    .fill('2026-10-12T18:00');
  await page.getByLabel('Event ends (season time zone)', { exact: false }).fill('2026-10-12T19:00');
  await page
    .locator('select[name="configuration[target_type]"]')
    .locator('..')
    .locator('.ts-control')
    .click();
  await page
    .locator('.ts-dropdown .option')
    .filter({ hasText: 'Percentage of active Rangers' })
    .click();
  await page.getByLabel('Staffing target value', { exact: false }).fill('101');
  await page
    .locator('select[name="configuration[counts_scheduled]"]')
    .locator('..')
    .locator('.ts-control')
    .click();
  await page
    .locator('.ts-dropdown .option')
    .filter({ hasText: 'Count scheduled Rangers across all areas' })
    .click();
  const attendees = page.locator('select[name="configuration[attendance_membership_ids][]"]');
  const ranger = await attendees
    .locator('option')
    .filter({ hasText: 'Casey Brooks' })
    .getAttribute('value');
  expect(ranger).toBeTruthy();
  // Orchid enhances multi-selects; operate the visible searchable control.
  const control = attendees.locator('..').locator('.ts-control');
  await control.click();
  await page.locator('.ts-dropdown .option').filter({ hasText: 'Casey Brooks' }).click();
  await page
    .getByLabel('Reason for event change', { exact: false })
    .fill('Configure one paid meeting');
  await page.getByRole('button', { name: 'Save event and attendance', exact: true }).click();
  await expect(page.getByText(/must not be greater than 100/).first()).toBeVisible();
  await expect(page.getByLabel('Event name', { exact: false })).toHaveValue('Monday Meeting');
  await page.getByLabel('Staffing target value', { exact: false }).fill('50');
  await page.getByRole('button', { name: 'Save event and attendance', exact: true }).click();
  await expect(
    page.getByText('Event and attendance saved. Area coverage is evaluated separately.').first(),
  ).toBeVisible();
  await page.reload();
  await expect(page.getByText('Casey Brooks · Paid · Full event')).toBeVisible();
  await expect(page.getByText(/Target: 4 · Active Rangers: 8/)).toBeVisible();
  const id = page.url().split('/').pop()!;
  const api = `${base}/events/${id}`;
  const saved = (await (await context.request.get(api)).json()) as {
    revision: number;
    attendance: { id: string }[];
    evaluation: { paid_attendance: { allocations: { elapsed_seconds: number }[] }[] };
  };
  expect(saved.evaluation.paid_attendance[0]!.allocations[0]!.elapsed_seconds).toBe(3600);
  expect(
    (
      await context.request.put(
        `/api/v1/organizations/${organization}/official-assignments/${randomUUID()}`,
        {
          headers,
          data: {
            expected_revision: null,
            membership_id: ranger,
            starts_at: '2026-10-13T01:00:00Z',
            ends_at: '2026-10-13T02:00:00Z',
            reason: 'Count an already scheduled Ranger only once',
          },
        },
      )
    ).status(),
  ).toBe(200);
  await page.reload();
  await expect(page.getByText(/Minimum counted throughout event: 1/)).toBeVisible();
  const peerInput = {
    expected_revision: saved.revision,
    expected_season_revision: 1,
    name: 'Manager updated meeting',
    starts_local: '2026-10-12 18:00:00',
    ends_local: '2026-10-12 19:00:00',
    paid: true,
    target_type: 'percentage',
    target_value: 50,
    counts_scheduled: true,
    attendance_membership_ids: [ranger],
    reason: 'Concurrent manager edit',
  };
  expect((await context.request.put(api, { headers, data: peerInput })).status()).toBe(200);
  await page.getByLabel('Event name', { exact: false }).fill('Retained local meeting name');
  await page.getByLabel('Reason for event change', { exact: false }).fill('My stale edit');
  await page.getByRole('button', { name: 'Save event and attendance', exact: true }).click();
  await expect(
    page.getByText(/Another manager changed this event or season/).first(),
  ).toBeVisible();
  await expect(page.getByLabel('Event name', { exact: false })).toHaveValue(
    'Retained local meeting name',
  );
  expect(((await (await context.request.get(api)).json()) as { name: string }).name).toBe(
    'Manager updated meeting',
  );
  expect(
    ((await (await context.request.get(`${base}/events`)).json()) as { total: number }).total,
  ).toBe(1);
  await mkdir('../docs/evidence/RUSH-020', { recursive: true });
  await page.screenshot({
    path: `../docs/evidence/RUSH-020/events-conflict-${test.info().project.name}.png`,
    fullPage: true,
  });
  await page.goto(`/admin/organizations/${organization}/seasons/${season}/events/${id}`);
  await page.screenshot({
    path: `../docs/evidence/RUSH-020/events-${test.info().project.name}.png`,
    fullPage: true,
  });
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
  ).toBe(true);
});

import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import type { SessionIdentity } from '../../src/data/api/session';
import { withLoginCooldown } from '../fixtures/login';

test('Management captures planning inputs and sees stale revisions without changing assignments', async ({
  page,
  context,
}) => {
  test.setTimeout(90_000);
  await context.request.get('/sanctum/csrf-cookie');
  const headers = {
    Accept: 'application/json',
    'X-XSRF-TOKEN': decodeURIComponent(
      (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value,
    ),
  };
  expect(
    (
      await withLoginCooldown(() =>
        context.request.post('/login', {
          headers,
          data: {
            email: 'riley.management@example.com',
            password: 'password',
          },
        }),
      )
    ).status(),
  ).toBe(200);
  headers['X-XSRF-TOKEN'] = decodeURIComponent(
    (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value,
  );
  const identity = (await (await context.request.get('/api/v1/session')).json()) as SessionIdentity;
  const organization = identity.memberships[0]!.organization.id;
  const season = randomUUID();
  const phase = randomUUID();
  const base = `/api/v1/organizations/${organization}`;
  const calendar = {
    name: `Planning ${test.info().project.name}`,
    starts_on: '2027-01-01',
    ends_on: '2027-12-31',
    timezone: 'America/Los_Angeles',
    week_starts_on: 1,
    expected_revision: null,
    phases: [{ id: phase, name: 'Full season', starts_on: '2027-01-01', ends_on: '2027-12-31' }],
  };
  expect(
    (await context.request.put(`${base}/seasons/${season}`, { headers, data: calendar })).status(),
  ).toBe(201);
  const coverageResponse = await context.request.put(`${base}/phases/${phase}/coverage`, {
    headers,
    data: {
      expected_revision: null,
      expected_season_revision: 1,
      reason: 'Planning inputs proof',
      shifts: [{ name: 'Day', start_time: '08:00', duration_minutes: 480 }],
      areas: [{ name: 'North' }],
      groups: [],
      staffing: [{ shift_name: 'Day', area_name: 'North', positions: 2 }],
    },
  });
  expect(coverageResponse.ok()).toBe(true);
  const coverage = (await coverageResponse.json()) as { shifts: { id: string }[] };
  expect(
    (
      await context.request.put(`${base}/phases/${phase}/policies`, {
        headers,
        data: {
          expected_revision: null,
          expected_season_revision: 1,
          expected_coverage_revision: 1,
          reason: 'Planning policies',
          weekly_target_minutes: 1920,
          planned_cap_minutes: 2400,
          planned_warning_minutes: 2100,
          actual_warning_minutes: 2400,
          baselines: [{ shift_id: coverage.shifts[0]!.id, classification: 'neutral' }],
          weekday_overrides: [],
          date_overrides: [],
        },
      })
    ).ok(),
  ).toBe(true);
  const screen = `/admin/organizations/${organization}/seasons/${season}/planning-inputs`;
  await page.goto(screen);
  await expect(page.getByRole('heading', { name: 'Constraint policy', exact: true })).toBeVisible();
  await page.getByLabel('First planning date (inclusive)').fill('2027-06-02');
  await page.getByLabel('Last planning date (inclusive)').fill('2027-06-04');
  await page.getByLabel('Minimum rest (minutes)').fill('600');
  await page.getByLabel('Reason for snapshot').fill('Review three days with ten-hour rest');
  await page.getByRole('button', { name: 'Capture planning inputs' }).click();
  await expect(
    page.getByText('Input revisions still match the Server.', { exact: false }),
  ).toBeVisible();
  await expect(page.getByText('3 shift/event opportunities', { exact: false })).toBeVisible();
  await expect(page.getByLabel('Minimum rest (minutes)')).toHaveValue('600');
  const snapshot = new URL(page.url()).searchParams.get('snapshot');
  expect(snapshot).toBeTruthy();
  const before = (await (
    await context.request.get(`${base}/planning-inputs/${snapshot}`)
  ).json()) as { fingerprint: string };
  expect(
    (
      await context.request.put(`${base}/seasons/${season}`, {
        headers,
        data: {
          ...calendar,
          expected_revision: 1,
          name: calendar.name + ' revised',
          reason: 'Change source revision',
        },
      })
    ).ok(),
  ).toBe(true);
  await page.reload();
  await expect(page.getByText('Inputs have changed.', { exact: false })).toBeVisible();
  const after = (await (
    await context.request.get(`${base}/planning-inputs/${snapshot}`)
  ).json()) as { fingerprint: string; stale: boolean };
  expect(after.fingerprint).toBe(before.fingerprint);
  expect(after.stale).toBe(true);
  await mkdir('../docs/evidence/RUSH-022', { recursive: true });
  await page.screenshot({
    path: `../docs/evidence/RUSH-022/planning-${test.info().project.name}.png`,
    fullPage: true,
  });
});

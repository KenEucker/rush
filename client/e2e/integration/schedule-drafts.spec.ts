import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import type { SessionIdentity } from '../../src/data/api/session';
import { withLoginCooldown } from '../fixtures/login';

test('Management generates edits removes and preserves conflicting draft input', async ({
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
  await page.getByRole('button', { name: 'Generate editable draft' }).click();
  await expect(page.getByRole('heading', { name: 'Draft proposals', exact: true })).toBeVisible();
  const draftUrl = page.url();
  const draftId = new URL(draftUrl).pathname.split('/').pop()!;
  type Draft = {
    revision: number;
    assignments: {
      id: string;
      membership_id: string;
      opportunity_id: string;
      starts_at: string;
      ends_at: string;
      override_reason: string | null;
    }[];
  };
  const draft = (await (await context.request.get(`${base}/drafts/${draftId}`)).json()) as Draft;
  expect(draft.assignments.length).toBeGreaterThan(0);
  await page.getByRole('link', { name: 'Edit Day assignment', exact: true }).first().click();
  await expect(page.locator('input[name="configuration[assignment_id]"]')).not.toHaveValue('');
  const rangerSelect = page.locator('select[name="configuration[membership_id]"]');
  const rangerIds = await rangerSelect
    .locator('option')
    .evaluateAll((options) => options.map((option) => (option as HTMLOptionElement).value));
  const replacement = rangerIds.find(
    (id) => !draft.assignments.some((assignment) => assignment.membership_id === id),
  )!;
  expect(replacement).toBeTruthy();
  await rangerSelect.locator('..').locator('.ts-control').click();
  await page.locator(`.ts-dropdown .option[data-value="${replacement}"]`).click();
  await page.getByLabel('Assignment starts (season time zone)').fill('2027-06-02T09:00');
  await page.getByLabel('Assignment ends (season time zone)').fill('2027-06-02T12:00');
  await page.getByLabel('Reason for draft change').fill('Partial coverage review');
  await page.getByRole('button', { name: 'Save assignment' }).click();
  await expect(page.getByText('Day · North (partial)', { exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByText('Day · North (partial)', { exact: true })).toBeVisible();
  await page
    .getByRole('row')
    .filter({ hasText: '(partial)' })
    .getByRole('link', { name: 'Edit Day assignment' })
    .click();
  const edited = (await (await context.request.get(`${base}/drafts/${draftId}`)).json()) as Draft;
  const partial = edited.assignments.find((assignment) =>
    assignment.starts_at.endsWith('T16:00:00Z'),
  )!;
  await expect(page.locator('input[name="configuration[assignment_id]"]')).toHaveValue(partial.id);
  expect(partial.membership_id).toBe(replacement);
  await expect(page.locator('input[name="configuration[expected_revision]"]')).toHaveValue(
    String(edited.revision),
  );
  expect(
    (
      await context.request.put(`${base}/drafts/${draftId}`, {
        headers,
        data: {
          expected_revision: edited.revision,
          reason: 'Competing manager review',
          candidates: edited.assignments.map(
            ({ membership_id, opportunity_id, starts_at, ends_at, override_reason }) => ({
              membership_id,
              opportunity_id,
              starts_at,
              ends_at,
              override_reason,
            }),
          ),
        },
      })
    ).ok(),
  ).toBe(true);
  await page.getByLabel('Reason for draft change').fill('Keep my conflicting input');
  await page.getByRole('button', { name: 'Save assignment' }).click();
  await expect(page.getByText(/Your input is retained/)).toBeVisible();
  await expect(page.getByLabel('Reason for draft change')).toHaveValue('Keep my conflicting input');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await mkdir('../docs/evidence/RUSH-023', { recursive: true });
  await page.screenshot({
    path: `../docs/evidence/RUSH-023/draft-${test.info().project.name}.png`,
    fullPage: true,
  });
  await page.goto(draftUrl);
  await page
    .getByRole('row')
    .filter({ hasText: '(partial)' })
    .getByRole('link', { name: 'Edit Day assignment' })
    .click();
  await expect(page.locator('input[name="configuration[assignment_id]"]')).toHaveValue(partial.id);
  await expect(page.locator('input[name="configuration[expected_revision]"]')).toHaveValue(
    String(edited.revision + 1),
  );
  await page.getByLabel('Reason for draft change').fill('Remove partial proposal');
  await page.getByRole('button', { name: 'Remove assignment' }).click();
  await expect(page.getByText('Day · North (partial)', { exact: true })).toHaveCount(0);
  const removed = (await (await context.request.get(`${base}/drafts/${draftId}`)).json()) as Draft;
  expect(removed.assignments).toHaveLength(draft.assignments.length - 1);
  await expect(page.locator('input[name="configuration[assignment_id]"]')).toHaveValue('');
  for (const [field, value] of [
    ['opportunity_id', partial.opportunity_id],
    ['membership_id', replacement],
  ]) {
    const select = page.locator(`select[name="configuration[${field}]"]`);
    if ((await select.inputValue()) !== value) {
      await select.locator('..').locator('.ts-control').click();
      await page.locator(`.ts-dropdown .option[data-value="${value}"]`).click();
    }
  }
  await page.getByLabel('Reason for draft change').fill('Add whole shift proposal');
  await page.getByRole('button', { name: 'Save assignment' }).click();
  await expect(
    page.getByText(`Revision ${removed.revision + 1} ·`, { exact: false }),
  ).toBeVisible();
  const added = (await (await context.request.get(`${base}/drafts/${draftId}`)).json()) as Draft;
  expect(added.assignments).toHaveLength(draft.assignments.length);
  expect(
    added.assignments.find(
      (assignment) =>
        assignment.membership_id === replacement &&
        assignment.opportunity_id === partial.opportunity_id,
    )?.starts_at,
  ).toBe('2027-06-02T15:00:00Z');
  await page.goto(screen);
  await expect(page.getByRole('heading', { name: 'Saved drafts', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: /Draft .* revision/ })).toHaveCount(1);
});

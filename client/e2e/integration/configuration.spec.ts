import { test, expect, type Page, type BrowserContext } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import type { SessionIdentity } from '../../src/data/api/session';
import { submitLogin } from '../fixtures/login';

test.use({ timezoneId: 'America/Los_Angeles', actionTimeout: 15_000 });

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
    'X-XSRF-TOKEN': decodeURIComponent(
      (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')!.value,
    ),
  };
}

async function chooseCoverage(page: Page, label: string, option: string, index = 0) {
  const field = page.locator(`select[aria-label="${label}"]`).nth(index).locator('..');
  if (!(await field.locator('.ts-dropdown').isVisible())) {
    const multiple = await field.locator('select').getAttribute('multiple');
    await field.locator(multiple === null ? '.ts-control' : '.ts-control input').click();
  }
  await field.locator('.ts-dropdown').getByText(option, { exact: true }).click();
}

async function choosePreference(page: Page, label: string, option: string) {
  await page.getByLabel(label, { exact: true }).click();
  await page.getByRole('option', { name: option, exact: true }).click();
}

test('Milestone 3: configure a season and reconcile Ranger inputs across coverage, policies and a paid event', async ({
  page,
  context,
  browser,
  baseURL,
}, info) => {
  test.setTimeout(240_000);
  const manager = await browser.newContext({
    baseURL: baseURL!,
    viewport: info.project.use.viewport!,
    timezoneId: 'America/Los_Angeles',
    extraHTTPHeaders: { Origin: baseURL! },
  });
  const year = new Date().getUTCFullYear() + 1;
  const monday = new Date(Date.UTC(year, 9, 1));
  monday.setUTCDate(1 + ((8 - monday.getUTCDay()) % 7));
  const meetingDate = monday.toISOString().slice(0, 10);
  const nextWeek = new Date(monday.getTime() + 7 * 86400000).toISOString().slice(0, 10);
  const seasonName = `M3 ${info.project.name} ${randomUUID().slice(0, 8)}`;
  const email =
    info.project.name === 'mobile' ? 'robin.ranger@example.com' : 'jamie.ranger@example.com';
  const rangerName = info.project.name === 'mobile' ? 'Robin Garcia' : 'Jamie Ortiz';
  try {
    const admin = await manager.newPage();
    await signIn(admin, 'avery.management@example.com');
    const identity = (await (
      await manager.request.get('/api/v1/session')
    ).json()) as SessionIdentity;
    const organization = identity.memberships[0]!.organization.id;
    const api = `/api/v1/organizations/${organization}`;
    const adminBase = `/admin/organizations/${organization}`;

    await test.step('Create adjacent low-staff and full-staff phases through Orchid', async () => {
      await admin.goto(`${adminBase}/seasons/new?year=${year}`);
      await admin.getByLabel('Season name', { exact: false }).fill(seasonName);
      await admin.getByLabel('Season starts on', { exact: false }).fill(`${year}-01-01`);
      await admin.getByLabel('Season ends on (inclusive)', { exact: false }).fill(`${year}-12-31`);
      await admin
        .locator('select[name="configuration[timezone]"]')
        .selectOption('America/Los_Angeles');
      await admin.locator('select[name="configuration[week_starts_on]"]').selectOption('1');
      for (const [name, start, end] of [
        ['Low staffing', `${year}-01-01`, `${year}-06-30`],
        ['Full staffing', `${year}-07-01`, `${year}-12-31`],
      ]) {
        await admin.getByRole('link', { name: 'Add phase', exact: true }).click();
        await admin.getByLabel('Phase name', { exact: true }).last().fill(name!);
        await admin.getByLabel('Phase starts on', { exact: true }).last().fill(start!);
        await admin.getByLabel('Phase ends on', { exact: true }).last().fill(end!);
      }
      await admin.getByRole('button', { name: 'Save season and phases', exact: true }).click();
      await expect(admin).toHaveURL(/\/seasons\/[0-9a-f-]{36}$/);
    });
    const seasonId = admin.url().split('/').at(-1)!;
    const seasonUrl = `${adminBase}/seasons/${seasonId}`;
    const season = (await (await manager.request.get(`${api}/seasons/${seasonId}`)).json()) as {
      phases: { id: string; name: string }[];
    };
    const low = season.phases.find((phase) => phase.name === 'Low staffing')!.id;
    const full = season.phases.find((phase) => phase.name === 'Full staffing')!.id;

    for (const [phase, shared, shifts, target] of [
      [low, true, [['Evening', '17:00', '360']], '1440'],
      [
        full,
        false,
        [
          ['Day', '06:00', '480'],
          ['Swing', '14:00', '480'],
          ['Night', '22:00', '480'],
        ],
        '1920',
      ],
    ] as const) {
      await test.step(`Configure ${shared ? 'limited shared' : '24/7 dedicated'} coverage and policies`, async () => {
        await admin.goto(seasonUrl);
        await admin.locator('#post-form').locator(`a[href$="/phases/${phase}/coverage"]`).click();
        for (const [name, start, duration] of shifts) {
          await admin.getByRole('link', { name: 'Add shift', exact: true }).click();
          await admin.getByLabel('Shift name', { exact: true }).last().fill(name);
          await admin.getByLabel('Shift local start', { exact: true }).last().fill(start);
          await admin
            .getByLabel('Shift duration in minutes', { exact: true })
            .last()
            .fill(duration);
        }
        for (const area of ['North', 'South']) {
          await admin.getByRole('link', { name: 'Add area', exact: true }).click();
          await admin.getByLabel('Area name', { exact: true }).last().fill(area);
        }
        if (shared) {
          await admin.getByRole('link', { name: 'Add shared group', exact: true }).click();
          await admin.getByLabel('Group name', { exact: true }).fill('Joint patrol');
          await chooseCoverage(admin, 'Group area names', 'North');
          await chooseCoverage(admin, 'Group area names', 'South');
        }
        for (const [index, [name]] of shifts.entries()) {
          await admin.getByRole('link', { name: 'Add staffing requirement', exact: true }).click();
          await chooseCoverage(admin, 'Staffing shift name', name, index);
          await chooseCoverage(
            admin,
            shared ? 'Staffing shared group' : 'Staffing dedicated area',
            shared ? 'Joint patrol' : 'North',
            index,
          );
          await admin
            .getByLabel('Required positions', { exact: true })
            .nth(index)
            .fill(shared ? '1' : '2');
        }
        await admin
          .getByLabel('Reason for coverage change', { exact: false })
          .fill('Milestone configuration');
        await admin.getByRole('button', { name: 'Save phase coverage', exact: true }).click();
        await expect(admin.getByText('Phase coverage saved.').first()).toBeVisible();
        await admin.reload();
        await expect(admin.getByLabel('Shift name', { exact: true })).toHaveCount(shifts.length);
        await admin.goto(seasonUrl);
        await admin.locator('#post-form').locator(`a[href$="/phases/${phase}/policies"]`).click();
        await admin.getByLabel('Weekly scheduling target (minutes)', { exact: false }).fill(target);
        await admin.getByLabel('Weekly planned cap (minutes)', { exact: false }).fill('1200');
        await admin
          .getByLabel('Planned hours warning threshold (minutes)', { exact: false })
          .fill('2100');
        await admin
          .getByLabel('Actual hours warning threshold (minutes)', { exact: false })
          .fill('2400');
        const classifications = admin.getByLabel('Baseline classification', { exact: true });
        for (let index = 0; index < shifts.length; index++)
          await classifications.nth(index).selectOption('neutral');
        await admin
          .getByLabel('Reason for policy change', { exact: false })
          .fill('Explicit planning inputs');
        await admin.getByRole('button', { name: 'Save scheduling policies', exact: true }).click();
        await expect(admin.getByText(/must be greater than or equal/).first()).toBeVisible();
        await expect(
          admin.getByLabel('Weekly scheduling target (minutes)', { exact: false }),
        ).toHaveValue(target);
        await admin.getByLabel('Weekly planned cap (minutes)', { exact: false }).fill('2400');
        await admin.getByRole('button', { name: 'Save scheduling policies', exact: true }).click();
        await expect(admin.getByText('Scheduling policies saved.').first()).toBeVisible();
      });
    }
    const coverageBefore = (await (
      await manager.request.get(`${api}/phases/${full}/coverage`)
    ).json()) as unknown;

    await test.step('Create just one paid Monday Meeting with explicit attendance', async () => {
      await admin.goto(seasonUrl);
      await admin
        .locator('#post-form')
        .locator(`a[href$="/seasons/${seasonId}/events/new"]`)
        .click();
      await admin.getByLabel('Event name', { exact: false }).fill('Monday Meeting');
      await admin
        .getByLabel('Event starts (season time zone)', { exact: false })
        .fill(`${meetingDate}T18:00`);
      await admin
        .getByLabel('Event ends (season time zone)', { exact: false })
        .fill(`${meetingDate}T19:00`);
      const target = admin.locator('select[name="configuration[target_type]"]').locator('..');
      await target.locator('.ts-control').click();
      await target.locator('.ts-dropdown').getByText('Number of Rangers', { exact: true }).click();
      await admin.getByLabel('Staffing target value', { exact: false }).fill('1');
      const attendance = admin
        .locator('select[name="configuration[attendance_membership_ids][]"]')
        .locator('..');
      await attendance.locator('.ts-control input').fill(rangerName);
      await attendance.locator('.ts-dropdown').getByText(rangerName, { exact: true }).click();
      await attendance.locator('.ts-control input').press('Escape');
      await attendance.locator('.ts-control input').press('Tab');
      await admin.getByLabel('Reason for event change', { exact: false }).fill('One paid meeting');
      await admin.getByRole('button', { name: 'Save event and attendance', exact: true }).click();
      await expect(
        admin
          .getByText('Event and attendance saved. Area coverage is evaluated separately.')
          .first(),
      ).toBeVisible();
      await expect(admin.getByText(/Minimum counted throughout event: 1/)).toBeVisible();
    });
    const eventUrl = admin.url();
    const eventId = eventUrl.split('/').at(-1)!;

    await signIn(page, email);
    await test.step('Ranger cannot read or write Management configuration', async () => {
      for (const path of [
        `/seasons/${seasonId}`,
        `/phases/${full}/coverage`,
        `/phases/${full}/policies`,
        `/seasons/${seasonId}/events/${eventId}`,
      ]) {
        expect((await context.request.get(`${api}${path}`)).status()).toBe(403);
        expect(
          (
            await context.request.put(`${api}${path}`, {
              headers: await headers(context),
              data: {},
            })
          ).status(),
        ).toBe(403);
      }
      expect((await context.request.get(seasonUrl)).status()).toBe(403);
    });
    await page.goto('/preferences');
    await choosePreference(page, 'Desired weekly hours', 'Set desired hours');
    await page.getByLabel('Desired hours', { exact: true }).fill('32');
    await choosePreference(page, 'Days off preference', 'Consecutive days off');
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await expect(
      page.getByTestId('preference-entry').filter({ hasText: 'Default preferences' }),
    ).toContainText('Synced — accepted by Server');

    await test.step('Persist availability and a weekly override together across offline reload', async () => {
      await page.goto('/availability');
      await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
      await context.setOffline(true);
      await page.getByLabel('Start date', { exact: true }).fill(meetingDate);
      await page.getByLabel('Start time', { exact: true }).fill('06:00 PM');
      await page.getByLabel('End date', { exact: true }).fill(meetingDate);
      await page.getByLabel('End time', { exact: true }).fill('08:00 PM');
      await page.getByRole('button', { name: 'Save unavailability' }).click();
      await expect(
        page.getByTestId('availability-entry').filter({ hasText: 'Pending —' }),
      ).toHaveCount(1);
      await page.goto('/preferences');
      await choosePreference(page, 'Profile', 'One week');
      await choosePreference(page, 'Season', seasonName);
      await page.getByLabel('Date in the week (YYYY-MM-DD)', { exact: true }).fill(meetingDate);
      await page.getByLabel('Use default preferred shifts', { exact: true }).uncheck();
      await choosePreference(page, 'Preferred shifts', 'Full staffing · Swing · 2:00 PM');
      await page.keyboard.press('Escape');
      await choosePreference(page, 'Days off preference', 'Separated days off');
      await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
      // A click does not await the Dexie transaction. Reload only after the UI
      // confirms the pending write, then verify that exact record survived.
      const pendingWeek = page.getByTestId('preference-entry').filter({ hasText: 'Pending —' });
      await expect(pendingWeek).toContainText('Days off: separated');
      const preferenceId = await pendingWeek.getAttribute('data-record-id');
      expect(preferenceId).toBeTruthy();
      await page.reload();
      const reloadedWeek = page.locator(`[data-record-id="${preferenceId}"]`);
      await expect(reloadedWeek).toContainText('Pending — saved on this device');
      await expect(reloadedWeek).toContainText('Days off: separated');
      await page.goto('/availability');
      await expect(
        page.getByTestId('availability-entry').filter({ hasText: 'Pending —' }),
      ).toHaveCount(1);
      // The Server must still count attendance until it accepts the offline report.
      await admin.goto(eventUrl);
      await expect(admin.getByText(/Minimum counted throughout event: 1/)).toBeVisible();
    });

    await test.step('Reconcile both domains and acknowledge the report without changing coverage', async () => {
      const reportId = (await page
        .getByTestId('availability-entry')
        .filter({ hasText: 'Pending —' })
        .getAttribute('data-record-id'))!;
      await context.setOffline(false);
      await page.reload();
      const report = page.locator(`[data-record-id="${reportId}"]`);
      await expect(report).toContainText('Synced — accepted by Server');
      await admin.goto(`${adminBase}/unavailability/new?year=${year}`);
      const notice = admin.locator(`[data-record-id="${reportId}"]`);
      await expect(notice).toContainText('Needs acknowledgment');
      await notice.getByRole('button', { name: 'Acknowledge as seen' }).click();
      await expect(notice).toContainText('Acknowledged as seen');
      await expect(admin).toHaveURL(new RegExp(`/unavailability/new\\?year=${year}$`));
      await page.reload();
      await expect(report).toContainText('Management acknowledged this revision as seen.');
      await admin.goto(eventUrl);
      await expect(admin.getByText(/Minimum counted throughout event: 0/)).toBeVisible();
      await expect(admin.getByText(`${rangerName} · Paid · Full event`)).toBeVisible();
      const events = (await (
        await manager.request.get(`${api}/seasons/${seasonId}/events`)
      ).json()) as { total: number };
      expect(events.total).toBe(1);
      expect(await (await manager.request.get(`${api}/phases/${full}/coverage`)).json()).toEqual(
        coverageBefore,
      );
      await page.goto('/preferences');
      const weekly = page
        .getByTestId('preference-entry')
        .filter({ hasText: 'Days off: separated' });
      await expect(weekly).toContainText('Synced — accepted by Server');
      await weekly.getByRole('button', { name: 'Edit preferences' }).click();
      await expect(page.getByTestId('effective-preferences')).toContainText('Desired hours: 32');
      await page.getByLabel('Date in the week (YYYY-MM-DD)', { exact: true }).fill(nextWeek);
      await expect(page.getByTestId('effective-preferences')).toContainText(
        'Days off: consecutive',
      );
      for (const surface of [page, admin]) {
        expect(
          await surface.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
        ).toBe(true);
      }
      await info.attach('configured-event', {
        body: await admin.screenshot({ fullPage: true }),
        contentType: 'image/png',
      });
      await info.attach('reconciled-preferences', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
      });
    });
  } finally {
    await context.setOffline(false);
    await manager.close();
  }
});

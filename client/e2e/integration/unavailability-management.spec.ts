import { test, expect, type Page, type BrowserContext } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
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

test('Management acknowledges offline reports and edits on behalf without repairing assignments', async ({
  page,
  context,
  browser,
  baseURL,
}, info) => {
  test.setTimeout(120_000);
  const manager = await browser.newContext({
    baseURL: baseURL!,
    viewport: info.project.use.viewport!,
    timezoneId: 'UTC',
  });
  try {
    await signIn(
      page,
      info.project.name === 'mobile' ? 'robin.ranger@example.com' : 'jamie.ranger@example.com',
    );
    const identity = (await (await context.request.get('/api/v1/session')).json()) as {
      memberships: { id: string; organization: { id: string } }[];
    };
    const member = identity.memberships[0]!;
    const base = `/api/v1/organizations/${member.organization.id}`;
    const admin = await manager.newPage();
    await signIn(admin, 'riley.management@example.com');
    await page.goto('/availability');
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
    await context.setOffline(true);
    await page.getByLabel('Start date', { exact: true }).fill('2026-12-01');
    await page.getByLabel('Start time', { exact: true }).fill('11:00 PM');
    await page.getByLabel('End date', { exact: true }).fill('2026-12-02');
    await page.getByLabel('End time', { exact: true }).fill('07:00 AM');
    await page.getByRole('button', { name: 'Save unavailability' }).click();
    const pending = page.getByTestId('availability-entry').filter({ hasText: 'Pending —' });
    await expect(pending).toHaveCount(1);
    const id = await pending.getAttribute('data-record-id');
    await page.reload();
    await expect(pending).toHaveCount(1);
    await context.setOffline(false);
    await page.reload();
    const row = page.locator(`[data-record-id="${id}"]`);
    await expect(row).toContainText('Awaiting Management acknowledgment.');
    const list = (await (await manager.request.get(`${base}/unavailability`)).json()) as {
      data: { id: string; starts_at: string; ends_at: string }[];
    };
    const report = list.data.find((value) => value.id === id)!;
    const assignment = await manager.request.put(`${base}/official-assignments/${randomUUID()}`, {
      headers: await headers(manager),
      data: {
        ...report,
        membership_id: member.id,
        expected_revision: null,
        reason: 'Published assignment conflict fixture',
      },
    });
    expect(assignment.ok()).toBe(true);
    await admin.goto('/admin/main');
    await admin.getByRole('link', { name: /Unavailability notices/ }).click();
    const notice = admin.locator(`[data-record-id="${id}"]`);
    await expect(notice).toContainText('Affected coverage gap needs review');
    await notice.getByRole('button', { name: 'Acknowledge as seen' }).click();
    await expect(notice).toContainText('Acknowledged as seen');
    await expect(notice).toContainText('Affected coverage gap needs review');
    await page.reload();
    await expect(row).toContainText('Management acknowledged this revision as seen.');
    await expect(row.getByTestId('assignment-conflict')).toBeVisible();
    await context.setOffline(true);
    await page.reload();
    await expect(row).toContainText('Management acknowledged this revision as seen.');
    await row.getByRole('button', { name: 'Edit time range' }).click();
    await page.getByLabel('End time', { exact: true }).fill('08:00 AM');
    await page.getByRole('button', { name: 'Save unavailability' }).click();
    await expect(row).toContainText('This saved change has not been acknowledged');
    await notice.getByRole('link', { name: 'Edit range' }).click();
    await expect(admin).toHaveURL(new RegExp(`/unavailability/${id}$`));
    const stale = await manager.newPage();
    await stale.goto(admin.url());
    await admin.getByLabel('End (UTC)', { exact: false }).fill(report.starts_at);
    await admin
      .getByLabel('Reason for Management change', { exact: false })
      .fill('Phone correction');
    await admin.getByRole('button', { name: 'Save on behalf of Ranger', exact: true }).click();
    await expect(admin.getByText(/must be a date after/).first()).toBeVisible();
    await admin
      .getByLabel('End (UTC)', { exact: false })
      .fill(new Date(Date.parse(report.ends_at) + 7200000).toISOString().replace('.000Z', 'Z'));
    await admin.getByRole('button', { name: 'Save on behalf of Ranger', exact: true }).click();
    await expect(notice).toContainText('Revision 2');
    await expect(notice).toContainText('Needs acknowledgment');
    await stale
      .getByLabel('Reason for Management change', { exact: false })
      .fill('Preserve stale input');
    await stale.getByRole('button', { name: 'Save on behalf of Ranger', exact: true }).click();
    await expect(stale.getByText(/This range changed/).first()).toBeVisible();
    await expect(stale.getByLabel('Reason for Management change', { exact: false })).toHaveValue(
      'Preserve stale input',
    );
    await stale.close();
    await context.setOffline(false);
    await page.reload();
    await expect(row).toContainText('Conflict — needs attention');
    await expect(row).toContainText('Your reported intent is preserved.');
    await mkdir('../docs/evidence/RUSH-018', { recursive: true });
    await admin.screenshot({
      path: `../docs/evidence/RUSH-018/${info.project.name}-management.png`,
      fullPage: true,
    });
    await page.screenshot({
      path: `../docs/evidence/RUSH-018/${info.project.name}-ranger.png`,
      fullPage: true,
    });
    expect(await admin.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await row.getByRole('button', { name: 'Discard this saved change' }).click();
    await page.getByRole('button', { name: 'Confirm discard' }).click();
    await expect(row).toContainText('Awaiting Management acknowledgment.');
    // Management also creates a new audited range using the same editor/service.
    await admin.getByRole('link', { name: 'Add unavailability on behalf of a Ranger' }).click();
    await expect(admin).toHaveURL(/\/unavailability\/new$/);
    await expect(admin.getByLabel('Start (UTC)', { exact: false })).toHaveValue('');
    await admin.locator('.ts-control').click();
    await admin
      .locator('.ts-dropdown')
      .getByText(info.project.name === 'mobile' ? 'Robin Garcia' : 'Jamie Ortiz', {
        exact: true,
      })
      .click();
    await expect(admin.locator('select[name="entry[membership_id]"]')).toHaveValue(member.id);
    await admin.getByLabel('Start (UTC)', { exact: false }).fill('2026-12-10T10:00:00Z');
    await admin.getByLabel('End (UTC)', { exact: false }).fill('2026-12-10T12:00:00Z');
    await admin
      .getByLabel('Reason for Management change', { exact: false })
      .fill('Emergency report by phone');
    await admin.getByRole('button', { name: 'Save on behalf of Ranger', exact: true }).click();
    await expect(admin.getByText('Unavailability saved.', { exact: false }).first()).toBeVisible();
  } finally {
    await manager.close();
  }
});

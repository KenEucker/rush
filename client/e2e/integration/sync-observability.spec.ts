import { test, expect, type Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Ranger overview' })).toBeVisible();
  await page.goto('/availability');
  await expect(page.getByTestId('sync-status')).toContainText('Synced with Server');
}

test('global recovery survives lost receipts and interrupted pulls, then resolves two-device conflicts explicitly', async ({
  browser,
  baseURL,
}, info) => {
  test.setTimeout(120_000);
  const options = {
    baseURL: baseURL!,
    viewport: info.project.use.viewport!,
    timezoneId: 'America/Los_Angeles',
  };
  const first = await browser.newContext(options);
  const second = await browser.newContext(options);
  const email =
    info.project.name === 'mobile' ? 'sam.ranger@example.com' : 'quinn.ranger@example.com';
  const evidence = resolve('../docs/evidence/RUSH-014');
  await mkdir(evidence, { recursive: true });
  try {
    const page = await first.newPage();
    await signIn(page, email);
    const lastSuccessful = await page.getByText('Last sync:', { exact: false }).textContent();
    let loseResponse = true;
    let committed = false;
    const ids: string[] = [];
    let recordId = '';
    await first.route('**/sync/push', async (route) => {
      const body = route.request().postDataJSON() as { operation_id: string; record_id: string };
      ids.push(body.operation_id);
      recordId = body.record_id;
      if (loseResponse) {
        if (!committed) {
          const response = await route.fetch();
          expect(response.ok()).toBe(true);
          expect((await response.json()).status).toBe('accepted');
          committed = true;
        }
        await route.abort('connectionreset');
      } else await route.continue();
    });
    await page.getByLabel('Start date', { exact: true }).fill('2026-12-01');
    await page.getByLabel('Start time', { exact: true }).fill('09:00 AM');
    await page.getByLabel('End date', { exact: true }).fill('2026-12-01');
    await page.getByLabel('End time', { exact: true }).fill('05:00 PM');
    await page.getByRole('button', { name: 'Save unavailability' }).click();
    const status = page.getByTestId('sync-status');
    const row = () => page.locator(`[data-record-id="${recordId}"]`);
    await expect(status).toContainText('Synchronization failed');
    await expect(row()).toContainText('Sync failed — saved for retry');
    await page.screenshot({
      path: resolve(evidence, info.project.name + '-failed.png'),
      fullPage: true,
    });
    await info.attach('failed-sync', {
      path: resolve(evidence, info.project.name + '-failed.png'),
      contentType: 'image/png',
    });

    // The command was accepted, but both its receipt and the following pull can
    // be lost. Local intent remains until ordered pull confirms acceptance.
    let interruptPull = true;
    await first.route('**/sync/pull', async (route) => {
      if (interruptPull) await route.abort('connectionreset');
      else await route.continue();
    });
    loseResponse = false;
    await status.getByRole('button', { name: 'Sync now' }).click();
    await expect(row()).toContainText('Syncing — waiting for Server confirmation');
    await expect(status).toContainText('Synchronization failed');
    expect(await page.getByText('Last sync:', { exact: false }).textContent()).toContain(
      lastSuccessful!.split('Last sync:')[1]!.trim(),
    );
    expect(new Set(ids).size).toBe(1);
    await page.reload();
    await expect(page.getByTestId('sync-status')).toContainText('1 pending change(s)');
    interruptPull = false;
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(row()).toContainText('Synced — accepted by Server');
    await expect(status).toContainText('0 pending change(s)');
    await expect(status).toContainText('Synced with Server');
    await page.goto('/');
    await expect(status).toContainText('Last sync:');
    await expect(status).toContainText('Synced with Server');
    await page.goto('/availability');
    await first.unroute('**/sync/push');
    await first.unroute('**/sync/pull');

    const other = await second.newPage();
    await signIn(other, email);
    const remoteRow = other.locator(`[data-record-id="${recordId}"]`);
    await expect(remoteRow).toContainText('5:00 PM');
    await first.setOffline(true);
    await row().getByRole('button', { name: 'Edit time range' }).click();
    await page.getByLabel('End time', { exact: true }).fill('06:00 PM');
    await page.getByRole('button', { name: 'Save unavailability' }).click();
    await expect(row()).toContainText('Pending — saved on this device');
    await page.reload();
    await expect(row()).toContainText('6:00 PM');
    await remoteRow.getByRole('button', { name: 'Edit time range' }).click();
    await other.getByLabel('End time', { exact: true }).fill('07:00 PM');
    await other.getByRole('button', { name: 'Save unavailability' }).click();
    await expect(remoteRow).toContainText('Synced — accepted by Server');
    await first.setOffline(false);
    await page.reload();
    await expect(row()).toContainText('Conflict — needs attention');
    await expect(row()).toContainText('6:00 PM');
    await expect(row()).toContainText('Current Server range:');
    await expect(row()).toContainText('7:00 PM');
    await expect(status).toContainText('1 rejected or conflicting change(s)');
    await status.getByRole('button', { name: 'Sync now' }).click();
    await expect(row()).toContainText('Conflict — needs attention');
    await page.screenshot({
      path: resolve(evidence, info.project.name + '-conflict.png'),
      fullPage: true,
    });
    await info.attach('device-conflict', {
      path: resolve(evidence, info.project.name + '-conflict.png'),
      contentType: 'image/png',
    });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await row().getByRole('button', { name: 'Discard this saved change' }).click();
    await page.getByRole('button', { name: 'Keep saved change' }).click();
    await expect(row()).toContainText('Conflict — needs attention');
    await row().getByRole('button', { name: 'Discard this saved change' }).click();
    await page.getByRole('button', { name: 'Confirm discard' }).click();
    await expect(row()).toContainText('Synced — accepted by Server');
    await expect(row()).toContainText('7:00 PM');
    await expect(status).toContainText('0 pending change(s)');
    await page.reload();
    await expect(row()).toContainText('7:00 PM');
    await expect(row()).not.toContainText('6:00 PM');
  } finally {
    await first.close();
    await second.close();
  }
});

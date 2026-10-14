import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { withLoginCooldown } from '../fixtures/login';

test('Management browses past starting years read-only and keeps browser tabs independent', async ({
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
  await page.goto('/admin/main');
  const menu = page.locator('#headerMenuCollapse');
  const yearMenu = menu.locator('#menu-management-year');
  const yearLabel = page.getByRole('link', { name: /^Year: \d{4}$/, includeHidden: true });
  const openYears = async () => {
    if (!(await menu.isVisible())) await page.locator('.header-toggler').click();
    await expect(yearMenu).not.toBeVisible();
    await yearLabel.click();
    await expect(yearMenu).toHaveClass(/\bshow\b/);
  };
  const currentYear = Number((await yearLabel.textContent())!.match(/\d{4}/)![0]);
  await expect(page.getByRole('button', { name: 'View year', exact: true })).toHaveCount(0);
  await expect(page.getByText('Archive browser proof', { exact: true })).toHaveCount(0);
  await openYears();
  await mkdir('../docs/evidence/RUSH-020', { recursive: true });
  await page.screenshot({
    path: `../docs/evidence/RUSH-020/year-menu-${test.info().project.name}.png`,
    fullPage: true,
  });
  await yearMenu.getByRole('link', { name: String(currentYear - 1), exact: true }).click();
  await expect(page.getByText(/Read-only archive/)).toBeVisible();
  await expect(page.getByRole('heading', { name: `${currentYear - 1} overview` })).toBeVisible();
  await expect(
    page.getByRole('rowheader', { name: 'Archive browser proof', exact: true }),
  ).toBeVisible();
  await page.reload();
  await page.screenshot({
    path: `../docs/evidence/RUSH-020/year-overview-${test.info().project.name}.png`,
    fullPage: true,
  });
  await expect(yearMenu).not.toBeVisible();
  if (!(await menu.isVisible())) await page.locator('.header-toggler').click();
  await menu.getByRole('link', { name: 'Seasons and phases', exact: true }).click();
  await menu
    .locator('#menu-seasons-and-phases')
    .getByRole('link', { name: /Archive browser proof/ })
    .click();
  await expect(page.getByLabel('Season name', { exact: false })).toBeDisabled();
  await expect(
    page.getByRole('button', { name: 'Save season and phases', exact: true }),
  ).toHaveCount(0);
  await page
    .getByRole('link', { name: 'Configure one-off events and attendance', exact: true })
    .click();
  await page.getByRole('link', { name: 'Archived event proof', exact: true }).click();
  await expect(page.getByLabel('Event name', { exact: false })).toHaveValue('Archived event proof');
  await expect(page.getByLabel('Event name', { exact: false })).toBeDisabled();
  await expect(
    page.getByRole('button', { name: 'Save event and attendance', exact: true }),
  ).toHaveCount(0);
  expect(
    (
      await context.request.post(`${page.url()}/save?year=${currentYear}`, {
        headers,
        data: { configuration: { name: 'Forged edit' } },
      })
    ).status(),
  ).toBe(403);
  await page.reload();
  await expect(yearLabel).toContainText(`Year: ${currentYear - 1}`);
  const otherTab = await context.newPage();
  await otherTab.goto('/admin/main');
  await expect(
    otherTab.getByRole('link', { name: /^Year: \d{4}$/, includeHidden: true }),
  ).toContainText(`Year: ${currentYear}`);
  await otherTab.close();
  await page.reload();
  await expect(page.getByLabel('Event name', { exact: false })).toBeDisabled();
  await page.screenshot({
    path: `../docs/evidence/RUSH-020/year-archive-${test.info().project.name}.png`,
    fullPage: true,
  });
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
  ).toBe(true);
  await openYears();
  await yearMenu.getByRole('link', { name: String(currentYear + 1), exact: true }).click();
  await expect(yearLabel).toContainText(`Year: ${currentYear + 1}`);
  await expect(yearMenu).not.toBeVisible();
  await expect(page.getByText(/Read-only archive/)).toHaveCount(0);
  await expect(
    page.getByRole('link', { name: 'Create a season to configure events' }),
  ).toBeVisible();
});

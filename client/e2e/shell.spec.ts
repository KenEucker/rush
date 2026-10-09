import { test, expect, type Page } from '@playwright/test';

function identity(role: 'ranger' | 'management' = 'ranger') {
  return {
    user: { id: 1, name: 'Casey Ranger', email: 'casey@example.test' },
    memberships: [
      { id: 'member-a', role, organization: { id: 'org-a', name: 'Demo Rangers' }, profile: null },
    ],
  };
}
async function session(page: Page, role: 'ranger' | 'management' = 'ranger') {
  await page.route('**/api/v1/session', (route) => route.fulfill({ json: identity(role) }));
}
async function openNavigation(page: Page) {
  if (!(await page.getByRole('navigation', { name: 'Primary navigation' }).isVisible())) {
    await page.getByRole('button', { name: 'Toggle navigation' }).click();
  }
}
test('Ranger can navigate with keyboard and sees no Management entry', async ({
  page,
}, testInfo) => {
  await session(page);
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Ranger overview' })).toBeVisible();
  await expect(page.locator('a[href="/admin"]')).toHaveCount(0);
  const skip = page.getByRole('link', { name: 'Skip to main content' });
  await skip.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('main')).toBeFocused();
  await openNavigation(page);
  await expect(page.getByText('Availability', { exact: true })).toBeVisible();
  const link = page.getByRole('link', { name: 'Calendar preview', exact: true });
  await link.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1, name: 'Calendar preview' })).toBeFocused();
  await expect(page).toHaveTitle('Calendar preview · RUSH');
  if (testInfo.project.name === 'mobile') {
    await expect(page.getByRole('navigation', { name: 'Primary navigation' })).not.toBeVisible();
  }
  await openNavigation(page);
  await expect(page.getByRole('link', { name: 'Calendar preview', exact: true })).toHaveAttribute(
    'aria-current',
    'page',
  );
  await page.screenshot({ path: testInfo.outputPath('ranger-navigation.png'), fullPage: true });
  await page.getByRole('link', { name: 'Your account', exact: true }).click();
  await expect(page.getByText('Demo Rangers', { exact: true })).toBeVisible();
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/logout', (route) => route.fulfill({ status: 204 }));
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Sign in to RUSH' })).toBeVisible();
  await expect(page.getByRole('navigation')).toHaveCount(0);
});
test('Management has same-origin Orchid entry and loses it on role revocation', async ({
  page,
}, testInfo) => {
  await session(page, 'management');
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Management overview' })).toBeVisible();
  await expect(
    page.getByRole('link', { name: 'Open administration', exact: true }),
  ).toHaveAttribute('href', '/admin');
  await page.screenshot({ path: testInfo.outputPath('management-overview.png'), fullPage: true });
  await session(page);
  await page.getByRole('link', { name: 'Open calendar preview' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Calendar preview' })).toBeVisible();
  await expect(page.locator('a[href="/admin"]')).toHaveCount(0);
});
test('deep links reject guests and recover from connection failure without stale content', async ({
  page,
}) => {
  await page.route('**/api/v1/session', (route) => route.fulfill({ status: 401 }));
  await page.goto('/calendar');
  await expect(page).toHaveURL(/\/sign-in$/);
  await page.route('**/api/v1/session', (route) => route.abort());
  await page.goto('/account');
  await expect(page.getByRole('alert')).toContainText('Unable to connect');
  await expect(page.getByRole('navigation')).toHaveCount(0);
  await session(page);
  await page.getByRole('button', { name: 'Retry connection' }).click();
  await expect(page.getByRole('heading', { name: 'Your RUSH account' })).toBeVisible();
  await page.route('**/api/v1/session', (route) => route.fulfill({ status: 401 }));
  await openNavigation(page);
  await page.getByRole('link', { name: 'Calendar preview', exact: true }).click();
  await expect(page).toHaveURL(/\/sign-in$/);
  await expect(page.getByText('Casey Ranger')).toHaveCount(0);
});
test('calendar, date and time controls are responsive and explicitly transient', async ({
  page,
}, testInfo) => {
  await session(page);
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/calendar');
  await expect(page.locator('.q-calendar-agenda')).toBeVisible();
  await expect(page.getByText('No assignments are loaded.', { exact: false })).toBeVisible();
  const day = await page.getByRole('heading', { level: 2 }).textContent();
  await page.getByRole('button', { name: 'Next day', exact: true }).click();
  await expect(page.getByRole('heading', { level: 2 })).not.toHaveText(day!);
  await page.getByRole('button', { name: 'Previous day', exact: true }).click();
  await expect(page.getByRole('heading', { level: 2 })).toHaveText(day!);
  await page.getByText('Try date and time controls', { exact: true }).click();
  await expect(page.locator('.q-date')).toBeVisible();
  const chosenDay = page.locator('.q-date').getByRole('button', { name: /^15 / });
  await chosenDay.focus();
  await page.keyboard.press('Enter');
  const chosenLabel = await page.getByRole('heading', { level: 2 }).textContent();
  expect(chosenLabel).toContain('15,');
  await chosenDay.click();
  await expect(page.getByRole('heading', { level: 2 })).toHaveText(chosenLabel!);
  await expect(page.locator('.q-time')).toHaveCount(2);
  await expect(page.getByTestId('preview-range')).toContainText('9:00 AM – 5:00 PM');
  const start = page.getByRole('region', { name: 'Start time', exact: true });
  const hour = start.getByRole('spinbutton', { name: 'Hour', exact: true });
  await hour.focus();
  await page.keyboard.press('ArrowUp');
  await expect(page.getByTestId('preview-range')).toContainText('10:00 AM');
  const minute = start.getByRole('spinbutton', { name: 'Minute', exact: true });
  await minute.focus();
  await page.keyboard.press('ArrowUp');
  await expect(page.getByTestId('preview-range')).toContainText('10:01 AM');
  await start.getByRole('button', { name: 'PM', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('End time must be after start time');
  await page.getByRole('checkbox', { name: 'Ends the next day (overnight)' }).click();
  await expect(page.getByTestId('preview-range')).toContainText('(overnight; next day)');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: testInfo.outputPath('calendar-controls.png'), fullPage: true });
  await page.reload();
  await page.getByText('Try date and time controls', { exact: true }).click();
  await expect(
    page.getByRole('checkbox', { name: 'Ends the next day (overnight)' }),
  ).not.toBeChecked();
  if (testInfo.project.name === 'mobile') {
    await page.setViewportSize({ width: 320, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  }
  expect(await page.evaluate(() => localStorage.length)).toBe(0);
  expect(errors).toEqual([]);
});
test('offline hint never claims synchronization and sign-out failure stays actionable', async ({
  page,
  context,
}) => {
  await session(page, 'management');
  await page.goto('/account');
  await expect(page.getByRole('heading', { name: 'Your RUSH account' })).toBeVisible();
  await context.setOffline(true);
  await expect(page.getByRole('status')).toContainText('Offline');
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Sign-out was not confirmed');
  await expect(page.getByText('Casey Ranger')).toBeVisible();
  await context.setOffline(false);
  await expect(page.getByRole('status')).toContainText('Sync not yet available');
});
test('unknown URLs have an accessible recovery path', async ({ page }) => {
  await page.goto('/missing');
  await expect(page.getByRole('heading', { name: 'Page not found' })).toBeFocused();
  await expect(page.getByRole('link', { name: 'Return to overview' })).toHaveAttribute('href', '/');
});

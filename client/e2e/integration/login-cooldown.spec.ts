import { expect, test } from '@playwright/test';
import { submitLogin } from '../fixtures/login';

test('fixture login honors a throttle cooldown and still exposes rejected credentials', async ({
  page,
}) => {
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill('riley.management@example.com');
  await page.getByLabel('Password', { exact: true }).fill('incorrect-password');
  const attempts: number[] = [];
  await page.route('**/login', async (route) => {
    attempts.push(Date.now());
    await route.fulfill({
      status: attempts.length === 1 ? 429 : 422,
      headers: attempts.length === 1 ? { 'Retry-After': '1' } : {},
      contentType: 'application/json',
      body: JSON.stringify({
        code: attempts.length === 1 ? 'rate_limited' : 'validation_failed',
        message: attempts.length === 1 ? 'Too many attempts.' : 'Check your details and try again.',
        errors: {},
      }),
    });
  });
  const originalTimeout = test.info().timeout;
  expect((await submitLogin(page)).status()).toBe(422);
  expect(attempts).toHaveLength(2);
  expect(attempts[1]! - attempts[0]!).toBeGreaterThanOrEqual(1000);
  expect(test.info().timeout).toBe(originalTimeout + 2000);
  await expect(page.getByRole('alert')).toContainText('Check your details and try again.');
  await expect(page).toHaveURL(/\/sign-in$/);
});

import { expect, test, type Page, type APIResponse } from '@playwright/test';
import { setTimeout } from 'node:timers/promises';

// The serial integration suite shares real per-account and per-IP login limits.
// Retry only one explicit throttle response; all other statuses reach the caller.
export async function withLoginCooldown<T extends Pick<APIResponse, 'status' | 'headers'>>(
  attempt: () => Promise<T>,
): Promise<T> {
  const response = await attempt();
  if (response.status() !== 429) return response;
  const seconds = Number(response.headers()['retry-after']);
  expect(Number.isInteger(seconds)).toBe(true);
  expect(seconds).toBeGreaterThan(0);
  expect(seconds).toBeLessThanOrEqual(60);
  const delay = (seconds + 1) * 1000;
  if (test.info().timeout !== 0) test.setTimeout(test.info().timeout + delay);
  await setTimeout(delay);
  return attempt();
}

export async function submitLogin(page: Page) {
  const passwordField = page.getByLabel('Password', { exact: true });
  const password = await passwordField.inputValue();
  return withLoginCooldown(async () => {
    // The real sign-in form clears its password after every response, including 429.
    await passwordField.fill(password);
    const [response] = await Promise.all([
      page.waitForResponse(
        (value) =>
          new URL(value.url()).pathname === '/login' && value.request().method() === 'POST',
      ),
      page.getByRole('button', { name: 'Sign in', exact: true }).click(),
    ]);
    return response;
  });
}

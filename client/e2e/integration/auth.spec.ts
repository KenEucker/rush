import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import type { SessionIdentity } from '../../src/data/api/session';

function rangerAccount() {
  // Separate fixture accounts keep the real per-account login limiter enabled.
  return test.info().project.name === 'mobile'
    ? 'jordan.ranger@example.com'
    : 'casey.ranger@example.com';
}
const managerEmail = 'avery.management@example.com';

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: /^(Ranger|Management) overview$/ })).toBeVisible();
}

async function identity(context: BrowserContext): Promise<SessionIdentity> {
  const response = await context.request.get('/api/v1/session', {
    headers: { Accept: 'application/json' },
  });
  expect(response.status()).toBe(200);
  expect(response.headers()['cache-control']).toContain('no-store');
  return response.json() as Promise<SessionIdentity>;
}

async function csrfHeaders(context: BrowserContext) {
  const cookie = (await context.cookies()).find((item) => item.name === 'XSRF-TOKEN');
  expect(cookie).toBeDefined();
  return {
    Accept: 'application/json',
    Origin: 'http://127.0.0.1:9187',
    'X-XSRF-TOKEN': decodeURIComponent(cookie!.value),
  };
}

test('guests cannot read private data and credentials require a real CSRF token', async ({
  page,
  context,
}) => {
  const rangerEmail = rangerAccount();
  expect(
    (
      await context.request.get('/api/v1/session', { headers: { Accept: 'application/json' } })
    ).status(),
  ).toBe(401);
  await page.goto('/account');
  await expect(page).toHaveURL(/\/sign-in$/);
  const deniedAdmin = await context.request.get('/admin', { maxRedirects: 0 });
  expect(deniedAdmin.status()).toBe(302);
  expect(deniedAdmin.headers().location).toContain('/sign-in');
  await context.request.get('/sanctum/csrf-cookie');
  const missingCsrf = await context.request.post('/login', {
    headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:9187' },
    data: { email: rangerEmail, password: 'password' },
  });
  expect(missingCsrf.status()).toBe(419);
  await page.getByLabel('Email', { exact: true }).fill(rangerEmail);
  await page.getByLabel('Password', { exact: true }).fill('incorrect-password');
  const rejectedLogin = page.waitForResponse(
    (response) => new URL(response.url()).pathname === '/login',
  );
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  expect((await rejectedLogin).status()).toBe(422);
  await expect(page.getByRole('alert')).toContainText('Check your details and try again.');
  expect(
    (
      await context.request.get('/api/v1/session', { headers: { Accept: 'application/json' } })
    ).status(),
  ).toBe(401);
});

test('Ranger cookie login survives reload, denies Orchid, and logout isolates the next account', async ({
  page,
  context,
}) => {
  const rangerEmail = rangerAccount();
  await signIn(page, rangerEmail);
  const ranger = await identity(context);
  expect(ranger.user.email).toBe(rangerEmail);
  expect(ranger.memberships.map((membership) => membership.role)).toEqual(['ranger']);
  await expect(page.locator('a[href="/admin"]')).toHaveCount(0);
  expect(
    (await context.request.get('/admin', { headers: { Accept: 'application/json' } })).status(),
  ).toBe(403);
  const cookies = await context.cookies();
  expect(cookies.some((cookie) => cookie.httpOnly && cookie.sameSite === 'Lax')).toBe(true);
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Ranger overview' })).toBeVisible();
  expect(
    (await context.request.post('/logout', { headers: { Accept: 'application/json' } })).status(),
  ).toBe(419);
  await page.goto('/account');
  await expect(page.getByText(rangerEmail, { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page).toHaveURL(/\/sign-in$/);
  expect(
    (
      await context.request.get('/api/v1/session', { headers: { Accept: 'application/json' } })
    ).status(),
  ).toBe(401);
  await page.goto('/account');
  await expect(page).toHaveURL(/\/sign-in$/);
  await expect(page.getByText(rangerEmail, { exact: true })).toHaveCount(0);
  await signIn(page, managerEmail);
  expect((await identity(context)).user.id).not.toBe(ranger.user.id);
  await page.goto('/account');
  await expect(page.getByText(managerEmail, { exact: true })).toBeVisible();
  await expect(page.getByText(rangerEmail, { exact: true })).toHaveCount(0);
});

test('Management opens Orchid on the same origin and profile reads/writes enforce the real actor', async ({
  page,
  context,
  browser,
}) => {
  const rangerEmail = rangerAccount();
  await signIn(page, managerEmail);
  await page.getByRole('link', { name: 'Open administration', exact: true }).click();
  await expect(page).toHaveURL('http://127.0.0.1:9187/admin/main');
  await expect(
    page.getByText('You are signed in with an active Management membership.'),
  ).toBeVisible();
  const orchidCss = page.locator('link[rel="stylesheet"]').first();
  const asset = await orchidCss.getAttribute('href');
  expect(asset).toBeTruthy();
  const stylesheet = await context.request.get(asset!);
  expect(stylesheet.status()).toBe(200);
  expect(stylesheet.headers()['content-type']).toContain('text/css');
  const manager = await identity(context);
  const rangerContext = await browser.newContext({
    baseURL: 'http://127.0.0.1:9187',
    extraHTTPHeaders: { Origin: 'http://127.0.0.1:9187' },
  });
  try {
    const rangerPage = await rangerContext.newPage();
    await signIn(rangerPage, rangerEmail);
    const ranger = await identity(rangerContext);
    const membership = ranger.memberships[0]!;
    const ownUrl = `/api/v1/organizations/${membership.organization.id}/profiles/${membership.profile!.id}`;
    const managerMembership = manager.memberships[0]!;
    const managerUrl = `/api/v1/organizations/${managerMembership.organization.id}/profiles/${managerMembership.profile!.id}`;
    const read = await rangerContext.request.get(ownUrl);
    expect(read.status()).toBe(200);
    const profile = (await read.json()) as { revision: number; display_name: string };
    expect((await context.request.get(ownUrl)).status()).toBe(200);
    expect(
      (
        await rangerContext.request.get(managerUrl, { headers: { Accept: 'application/json' } })
      ).status(),
    ).toBe(403);
    const data = { expected_revision: profile.revision, display_name: profile.display_name };
    expect(
      (
        await rangerContext.request.patch(ownUrl, { headers: { Accept: 'application/json' }, data })
      ).status(),
    ).toBe(419);
    expect(
      (await context.request.patch(ownUrl, { headers: await csrfHeaders(context), data })).status(),
    ).toBe(403);
    // Authorized no-op exercises the real write path without shared-fixture mutations.
    expect(
      (
        await rangerContext.request.patch(ownUrl, {
          headers: await csrfHeaders(rangerContext),
          data,
        })
      ).status(),
    ).toBe(200);
  } finally {
    await rangerContext.close();
  }
});

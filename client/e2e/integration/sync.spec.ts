import { test, expect, type Page } from '@playwright/test';
import { build } from 'vite';
import { resolve } from 'node:path';
import type { SessionIdentity } from '../../src/data/api/session';
import type { MemberProfile } from '../../src/data/api/profiles';
import type * as Harness from '../fixtures/sync-harness';

declare global {
  interface Window {
    RushSyncHarness: typeof Harness;
  }
}
let harness: string;
test.beforeAll(async () => {
  const result = await build({
    configFile: false,
    logLevel: 'error',
    build: {
      write: false,
      minify: false,
      lib: {
        entry: resolve('e2e/fixtures/sync-harness.ts'),
        name: 'RushSyncHarness',
        formats: ['iife'],
      },
    },
  });
  const output = Array.isArray(result) ? result[0] : result;
  if (!output || !('output' in output)) throw new Error('Expected a bundled test harness');
  const chunk = output.output.find((entry) => entry.type === 'chunk');
  if (!chunk) throw new Error('Missing test harness');
  harness = chunk.code;
});

test('coordinator preserves offline intent through reload, serializes tabs, and retains real revision conflicts', async ({
  page,
  context,
}) => {
  const email =
    test.info().project.name === 'mobile'
      ? 'morgan.ranger@example.com'
      : 'taylor.ranger@example.com';
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Ranger overview' })).toBeVisible();
  const session = (await (await context.request.get('/api/v1/session')).json()) as SessionIdentity;
  const membership = session.memberships[0]!;
  const scope = { accountId: session.user.id, organizationId: membership.organization.id };
  const id = membership.profile!.id;
  const url = '/api/v1/organizations/' + scope.organizationId + '/profiles/' + id;
  const initial = (await (await context.request.get(url)).json()) as MemberProfile;
  // Keep this infrastructure harness separate from the session-owned coordinator
  // now exercised by availability.spec.ts. This public page retains the real
  // cookie session but does not start the application's authenticated workspace.
  await page.goto('/sign-in');
  async function attach(target: Page) {
    await target.addScriptTag({ content: harness });
    await target.evaluate((scope) => window.RushSyncHarness.open(scope), scope);
  }
  await attach(page);
  await page.evaluate(() => window.RushSyncHarness.sync());
  await context.setOffline(true);
  const operation = await page.evaluate(
    ({ id, revision }) =>
      window.RushSyncHarness.stage(id, {
        expected_revision: revision,
        display_name: 'Offline coordinator proof',
      }),
    { id, revision: initial.revision },
  );
  expect((await page.evaluate(() => window.RushSyncHarness.snapshot())).pending).toHaveLength(1);
  await page.evaluate(() => window.RushSyncHarness.close());
  // RUSH-011/012 own offline shell startup. This tests durable document reload.
  await context.setOffline(false);
  await page.reload();
  await attach(page);
  expect(
    (await page.evaluate(() => window.RushSyncHarness.snapshot())).pending[0]?.operationId,
  ).toBe(operation.operation_id);
  const other = await context.newPage();
  await other.goto('/sign-in');
  await attach(other);
  let pushes = 0;
  context.on('request', (request) => {
    if (request.url().endsWith('/sync/push')) pushes++;
  });
  await Promise.all([
    page.evaluate(() => window.RushSyncHarness.sync()),
    other.evaluate(() => window.RushSyncHarness.sync()),
  ]);
  expect(pushes).toBe(1); // Native Web Locks serialize both actual IndexedDB connections.
  const snapshot = await page.evaluate(() => window.RushSyncHarness.snapshot());
  expect(snapshot.pending).toEqual([]);
  expect(snapshot.records).toEqual([]);
  expect(snapshot.profiles[0]?.value).toMatchObject({
    display_name: 'Offline coordinator proof',
    revision: initial.revision + 1,
  });
  await other.evaluate(() => window.RushSyncHarness.close());
  await other.close();

  const conflict = await page.evaluate(
    ({ id, revision }) =>
      window.RushSyncHarness.stage(id, {
        expected_revision: revision,
        display_name: 'Keep conflicting intent',
      }),
    { id, revision: initial.revision + 1 },
  );
  const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')!;
  const remote = await context.request.patch(url, {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) },
    data: { expected_revision: initial.revision + 1, display_name: 'Newer Server edit' },
  });
  expect(remote.status()).toBe(200);
  await page.evaluate(() => window.RushSyncHarness.sync());
  const resolved = await page.evaluate(() => window.RushSyncHarness.snapshot());
  expect(resolved.pending).toMatchObject([
    {
      operationId: conflict.operation_id,
      state: 'conflict',
      problem: { code: 'revision_conflict' },
    },
  ]);
  expect(resolved.records[0]?.value).toMatchObject({ display_name: 'Keep conflicting intent' });
  expect(resolved.profiles[0]?.value).toMatchObject({
    display_name: 'Newer Server edit',
    revision: initial.revision + 2,
  });
  await page.evaluate(() => window.RushSyncHarness.close());
});

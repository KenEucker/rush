import 'fake-indexeddb/auto';
import { Dexie, type DexieOptions } from 'dexie';
import { afterEach, expect, it, vi } from 'vitest';
import contract from '../../../../docs/contracts/member-profile.example.json';
import { AccountDatabase, databaseName } from '../database/database';
import type { AccountScope } from '../database/types';
import { openAccountStorage, PendingWorkError, type AccountStorage } from './accountStorage';

const scope: AccountScope = { accountId: 1, organizationId: contract.profile.organization_id };
const stores: AccountStorage[] = [];
const names = new Set<string>();
async function open(target = scope) {
  names.add(databaseName(target));
  const storage = await openAccountStorage(target);
  stores.push(storage);
  return storage;
}
const intent = () => ({
  operationId: crypto.randomUUID(),
  recordType: 'test-record',
  recordId: crypto.randomUUID(),
  expectedRevision: 1,
  command: { note: 'Preserve my intent' },
  localValue: { note: 'Pending, not official' },
});
afterEach(async () => {
  vi.restoreAllMocks();
  stores.splice(0).forEach((storage) => storage.close());
  for (const name of names) await Dexie.delete(name);
  names.clear();
});

it('persists authorized profiles, metadata and opaque checkpoints after reopening', async () => {
  let storage = await open();
  await storage.profiles.cache([contract.profile]);
  const checkpoint = {
    stream: 'profiles',
    token: 'opaque:server:42',
    lastSuccessfulSyncAt: '2026-10-09T12:00:00Z',
  };
  await storage.checkpoints.put(checkpoint);
  storage.close();
  storage = await open();
  expect((await storage.profiles.get(contract.profile.id))?.value).toEqual(contract.profile);
  expect(await storage.checkpoints.get('profiles')).toEqual(checkpoint);
  expect(await storage.metadata.get()).toMatchObject({
    ...scope,
    schemaVersion: 1,
    lastCachedAt: expect.any(String),
  });
});

it('isolates identical record, operation and stream keys across accounts and organizations', async () => {
  const a = await open();
  const b = await open({ ...scope, accountId: 2 });
  const c = await open({ ...scope, organizationId: crypto.randomUUID() });
  const pending = intent();
  await a.profiles.cache([contract.profile]);
  await a.pending.stage(pending);
  await a.checkpoints.put({
    stream: 'profiles',
    token: 'a',
    lastSuccessfulSyncAt: '2026-10-09T00:00:00Z',
  });
  for (const other of [b, c]) {
    expect(await other.profiles.list()).toEqual([]);
    expect(await other.pending.list()).toEqual([]);
    expect(await other.pending.records()).toEqual([]);
    expect(await other.checkpoints.get('profiles')).toBeUndefined();
    await other.pending.stage(pending);
  }
  await b.clearCache({ discardPending: true });
  expect(await a.pending.list()).toHaveLength(1);
  expect(await c.pending.list()).toHaveLength(1);
});

it('freezes the selected scope and rejects foreign organization cache writes atomically', async () => {
  const mutable = { ...scope };
  const storage = await open(mutable);
  mutable.organizationId = crypto.randomUUID();
  await expect(
    storage.profiles.cache([
      contract.profile,
      { ...contract.profile, organization_id: mutable.organizationId },
    ]),
  ).rejects.toMatchObject({ code: 'invalid_response' });
  expect(await storage.profiles.list()).toEqual([]);
  expect((await storage.metadata.get())?.organizationId).toBe(scope.organizationId);
});

it('allowlists cached fields and never replaces a Server revision with stale or equal data', async () => {
  const storage = await open();
  await storage.profiles.cache([
    { ...contract.profile, revision: 3, ...{ secret: 'must not persist' } },
  ]);
  await storage.profiles.cache([{ ...contract.profile, display_name: 'stale', revision: 2 }]);
  await storage.profiles.cache([
    { ...contract.profile, display_name: 'same revision', revision: 3 },
  ]);
  expect((await storage.profiles.get(contract.profile.id))?.value).toEqual({
    ...contract.profile,
    revision: 3,
  });
});

it('commits command and local representation together, preserving multiple intents across reload', async () => {
  const storage = await open();
  const first = intent();
  await storage.pending.stage(first);
  await storage.pending.stage({
    ...first,
    operationId: crypto.randomUUID(),
    localValue: { note: 'second edit' },
  });
  storage.close();
  const reopened = await open();
  expect(await reopened.pending.list()).toHaveLength(2);
  expect(await reopened.pending.records()).toHaveLength(2);
  expect(await reopened.pending.list()).toContainEqual(
    expect.objectContaining({
      operationId: first.operationId,
      command: first.command,
      state: 'pending',
      attempts: 0,
      problem: null,
    }),
  );
  expect(await reopened.profiles.list()).toEqual([]);
});

it('rejects duplicate operation IDs without replacing the original intent', async () => {
  const storage = await open();
  const pending = intent();
  await storage.pending.stage(pending);
  await expect(
    storage.pending.stage({ ...pending, localValue: { note: 'overwrite' } }),
  ).rejects.toMatchObject({ code: 'write_failed' });
  await expect(
    storage.pending.stage({ ...pending, operationId: pending.operationId.toUpperCase() }),
  ).rejects.toMatchObject({ code: 'write_failed' });
  expect(await storage.pending.records()).toEqual([
    {
      operationId: pending.operationId,
      recordType: pending.recordType,
      recordId: pending.recordId,
      value: pending.localValue,
    },
  ]);
});

it('rolls back the command if the local representation cannot be stored', async () => {
  const storage = await open();
  const pending = intent();
  // An uncloneable value fails the second write in the real IndexedDB transaction.
  const localValue = { invalid: () => undefined } as unknown as typeof pending.localValue;
  await expect(storage.pending.stage({ ...pending, localValue })).rejects.toMatchObject({
    code: 'write_failed',
  });
  expect(await storage.pending.list()).toEqual([]);
  expect(await storage.pending.records()).toEqual([]);
});

it('refuses cache removal with pending work and requires explicit discard', async () => {
  const storage = await open();
  await storage.profiles.cache([contract.profile]);
  await storage.pending.stage(intent());
  await storage.checkpoints.put({
    stream: 'profiles',
    token: 'a',
    lastSuccessfulSyncAt: '2026-10-09T00:00:00Z',
  });
  await expect(storage.clearCache()).rejects.toBeInstanceOf(PendingWorkError);
  expect(await storage.profiles.list()).toHaveLength(1);
  await storage.clearCache({ discardPending: true });
  expect(await storage.profiles.list()).toEqual([]);
  expect(await storage.pending.list()).toEqual([]);
  expect(await storage.pending.records()).toEqual([]);
  expect(await storage.checkpoints.get('profiles')).toBeUndefined();
  expect((await storage.metadata.get())?.lastCachedAt).toBeNull();
});

it.each(['failed', 'rejected', 'conflict', 'syncing'] as const)(
  'retains %s intent and blocks accidental removal',
  async (state) => {
    const storage = await open();
    const pending = intent();
    await storage.pending.stage(pending);
    const db = new AccountDatabase(scope);
    await db.initialize();
    await db.pendingCommands.update(pending.operationId, {
      state,
      problem: { code: 'test_problem', message: 'Try again after review' },
    });
    db.close();
    storage.close();
    const reopened = await open();
    expect(await reopened.pending.list()).toContainEqual(
      expect.objectContaining({
        state,
        command: pending.command,
        problem: { code: 'test_problem', message: 'Try again after review' },
      }),
    );
    await expect(reopened.clearCache()).rejects.toBeInstanceOf(PendingWorkError);
  },
);

it('keeps cross-tab writes consistent without an in-memory operational cache', async () => {
  const a = await open();
  const b = await open();
  await Promise.all([
    a.profiles.cache([{ ...contract.profile, revision: 4 }]),
    b.profiles.cache([{ ...contract.profile, revision: 2 }]),
  ]);
  expect((await a.profiles.get(contract.profile.id))?.value.revision).toBe(4);
  expect((await b.profiles.get(contract.profile.id))?.value.revision).toBe(4);
  const pending = intent();
  const results = await Promise.allSettled([a.pending.stage(pending), b.pending.stage(pending)]);
  expect(results.filter((result) => result.status === 'fulfilled')).toHaveLength(1);
  expect(await a.pending.records()).toHaveLength(1);
});

it('invalidates old handles on close without discarding persisted work', async () => {
  const storage = await open();
  await storage.pending.stage(intent());
  storage.close();
  await expect(storage.pending.list()).rejects.toMatchObject({ code: 'closed' });
  await expect(storage.pending.stage(intent())).rejects.toMatchObject({ code: 'closed' });
  expect(await (await open()).pending.list()).toHaveLength(1);
});

it('fails explicitly when IndexedDB is unavailable', async () => {
  await expect(
    openAccountStorage(scope, {
      indexedDB: undefined,
      IDBKeyRange: undefined,
    } as unknown as DexieOptions),
  ).rejects.toMatchObject({ code: 'unavailable' });
});

it.each([0, -1, NaN, 1.5])(
  'rejects invalid account scope %s before opening storage',
  (accountId) => {
    expect(() => databaseName({ ...scope, accountId })).toThrow(TypeError);
  },
);

it('rejects missing organization scope and malformed commands', async () => {
  expect(() => databaseName({ ...scope, organizationId: '' })).toThrow(TypeError);
  const storage = await open();
  await expect(storage.pending.stage({ ...intent(), operationId: 'invalid' })).rejects.toThrow(
    TypeError,
  );
  await expect(storage.pending.stage({ ...intent(), expectedRevision: 0 })).rejects.toThrow(
    TypeError,
  );
  expect(await storage.pending.list()).toEqual([]);
});

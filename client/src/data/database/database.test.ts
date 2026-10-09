import 'fake-indexeddb/auto';
import { Dexie } from 'dexie';
import { IDBObjectStore } from 'fake-indexeddb';
import { afterEach, expect, it, vi } from 'vitest';
import contract from '../../../../docs/contracts/member-profile.example.json';
import { AccountDatabase, databaseName, schemaV1, schemaV2 } from './database';
import { storageError } from './errors';
import { openAccountStorage, type AccountStorage } from '../repositories/accountStorage';

const scope = { accountId: 21, organizationId: contract.profile.organization_id };
const name = databaseName(scope);
const connections: { close: () => void }[] = [];
afterEach(async () => {
  vi.restoreAllMocks();
  connections.splice(0).forEach((db) => db.close());
  await Dexie.delete(name);
});
async function open(): Promise<AccountStorage> {
  const storage = await openAccountStorage(scope);
  connections.push(storage);
  return storage;
}
const pending = () => ({
  operationId: crypto.randomUUID(),
  recordType: 'test-record',
  recordId: crypto.randomUUID(),
  expectedRevision: null,
  command: { note: 'Keep this' },
  localValue: { note: 'Pending' },
});

it('creates the versioned schema with a cold cache and no invented sync checkpoint', async () => {
  const storage = await open();
  expect(await storage.metadata.get()).toMatchObject({
    ...scope,
    key: 'cache',
    schemaVersion: 2,
    lastCachedAt: null,
  });
  expect(await storage.checkpoints.get('profiles')).toBeUndefined();
  const db = new AccountDatabase(scope);
  connections.push(db);
  await db.initialize();
  expect(db.backendDB().version).toBe(20);
  expect(db.tables.map((table) => table.name).sort()).toEqual(Object.keys(schemaV2).sort());
});

it('rejects a future schema without erasing commands and checkpoints', async () => {
  const storage = await open();
  await storage.profiles.cache([contract.profile]);
  const intent = pending();
  await storage.pending.stage(intent);
  const checkpoint = {
    stream: 'profiles',
    token: 'server-token',
    lastSuccessfulSyncAt: '2026-10-09T12:00:00Z',
  };
  await storage.checkpoints.put(checkpoint);
  const before = await storage.metadata.get();

  // A test-only future version verifies that old Client code fails closed.
  const upgraded = new Dexie(name);
  connections.push(upgraded);
  upgraded.version(2).stores(schemaV2);
  upgraded
    .version(3)
    .stores({ profiles: 'id, cachedAt' })
    .upgrade(async (tx) => {
      await tx.table('metadata').update('cache', { schemaVersion: 3 });
    });
  await upgraded.open(); // Closes the old connection through versionchange.
  expect(await upgraded.table('profiles').get(contract.profile.id)).toMatchObject({
    value: contract.profile,
  });
  expect(await upgraded.table('pendingCommands').get(intent.operationId)).toMatchObject({
    command: intent.command,
  });
  expect(await upgraded.table('pendingRecords').get(intent.operationId)).toMatchObject({
    value: intent.localValue,
  });
  expect(await upgraded.table('checkpoints').get('profiles')).toEqual(checkpoint);
  expect(await upgraded.table('metadata').get('cache')).toEqual({ ...before, schemaVersion: 3 });
  await expect(storage.profiles.list()).rejects.toMatchObject({ code: 'closed' });
  upgraded.close();
  await expect(open()).rejects.toMatchObject({ code: 'schema' });
});

it('rolls back a failed migration without erasing previously saved user intent', async () => {
  const storage = await open();
  const intent = pending();
  await storage.pending.stage(intent);
  storage.close();
  const failed = new Dexie(name);
  connections.push(failed);
  failed.version(2).stores(schemaV2);
  failed
    .version(3)
    .stores({ profiles: 'id, cachedAt' })
    .upgrade(async (tx) => {
      await tx.table('pendingRecords').clear();
      await tx.table('metadata').update('cache', { schemaVersion: 3 });
      throw new Error('Simulated failed migration');
    });
  await expect(failed.open()).rejects.toThrow('Simulated failed migration');
  failed.close();
  const reopened = await open();
  expect(await reopened.pending.records()).toHaveLength(1);
  expect(await reopened.pending.list()).toHaveLength(1);
  expect((await reopened.metadata.get())?.schemaVersion).toBe(2);
});

it('reports blocked upgrades instead of waiting indefinitely or deleting the database', async () => {
  const blocker = new Dexie(name);
  connections.push(blocker);
  blocker.version(0.1).stores({ seed: 'id' });
  blocker.on('versionchange', () => false);
  await blocker.open();
  await expect(open()).rejects.toMatchObject({ code: 'blocked' });
  blocker.close();
});

it('rejects inconsistent partition metadata without clearing records', async () => {
  const storage = await open();
  await storage.pending.stage(pending());
  storage.close();
  const db = new AccountDatabase(scope);
  connections.push(db);
  await db.initialize();
  await db.metadata.update('cache', { accountId: 999 });
  db.close();
  await expect(open()).rejects.toMatchObject({ code: 'schema' });
  await db.open();
  expect(await db.pendingCommands.count()).toBe(1);
});

it('surfaces quota failure and rolls back both sides of a pending write', async () => {
  const storage = await open();
  // The fault injector below explicitly restores the store as the method receiver.
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const add = IDBObjectStore.prototype.add;
  vi.spyOn(IDBObjectStore.prototype, 'add').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'pendingRecords')
      throw new DOMException('Storage full', 'QuotaExceededError');
    return add.apply(this, args);
  });
  await expect(storage.pending.stage(pending())).rejects.toMatchObject({ code: 'quota' });
  expect(await storage.pending.list()).toEqual([]);
  expect(await storage.pending.records()).toEqual([]);
});

it('reports failed reads without substituting an empty cache', async () => {
  const storage = await open();
  vi.spyOn(IDBObjectStore.prototype, 'get').mockImplementation(() => {
    throw new DOMException('Read failed', 'UnknownError');
  });
  await expect(storage.profiles.get(contract.profile.id)).rejects.toMatchObject({
    code: 'read_failed',
  });
});

it('recreates an evicted empty database as cold, never as synchronized', async () => {
  const storage = await open();
  await storage.profiles.cache([contract.profile]);
  await storage.checkpoints.put({
    stream: 'profiles',
    token: 'before-eviction',
    lastSuccessfulSyncAt: '2026-10-09T00:00:00Z',
  });
  storage.close();
  await Dexie.delete(name); // Simulates browser/user eviction, not app recovery behavior.
  const reopened = await open();
  expect(await reopened.profiles.list()).toEqual([]);
  expect(await reopened.checkpoints.get('profiles')).toBeUndefined();
  expect((await reopened.metadata.get())?.lastCachedAt).toBeNull();
});

it.each([
  ['SecurityError', 'unavailable'],
  ['InvalidStateError', 'unavailable'],
  ['UpgradeError', 'schema'],
  ['SchemaError', 'schema'],
  ['VersionError', 'schema'],
  ['AbortError', 'write_failed'],
])('maps %s to actionable %s without hiding the original failure', (name, code) => {
  const cause = new DOMException('fixture', name);
  expect(storageError(cause, 'write_failed')).toMatchObject({ code, cause });
});

it('migrates the released v1 database to v2 without losing any existing store', async () => {
  const legacy = new Dexie(name);
  connections.push(legacy);
  legacy.version(1).stores(schemaV1);
  await legacy.open();
  const intent = pending();
  await legacy.table('metadata').put({
    key: 'cache',
    ...scope,
    schemaVersion: 1,
    createdAt: '2026-10-09T00:00:00Z',
    lastCachedAt: null,
  });
  await legacy
    .table('profiles')
    .put({ id: contract.profile.id, value: contract.profile, cachedAt: '2026-10-09T00:00:00Z' });
  await legacy.table('pendingCommands').put({
    ...intent,
    state: 'failed',
    attempts: 2,
    problem: { code: 'network', message: 'Retry' },
    createdAt: '2026-10-09T00:00:00Z',
  });
  await legacy.table('pendingRecords').put({
    operationId: intent.operationId,
    recordType: intent.recordType,
    recordId: intent.recordId,
    value: intent.localValue,
  });
  await legacy.table('checkpoints').put({
    stream: 'profiles',
    token: 'v1-token',
    lastSuccessfulSyncAt: '2026-10-09T00:00:00Z',
  });
  legacy.close();
  const storage = await open();
  expect((await storage.metadata.get())?.schemaVersion).toBe(2);
  expect((await storage.profiles.get(contract.profile.id))?.value).toEqual(contract.profile);
  expect(await storage.pending.list()).toMatchObject([
    { operationId: intent.operationId, attempts: 2, state: 'failed' },
  ]);
  expect(await storage.pending.records()).toMatchObject([{ value: intent.localValue }]);
  expect((await storage.checkpoints.get('profiles'))?.token).toBe('v1-token');
  expect(await storage.sync.state()).toMatchObject({ lastSuccessfulSyncAt: null, failures: 0 });
});

it('rolls back the actual v1 to v2 migration on storage failure and retains old intent', async () => {
  const legacy = new Dexie(name);
  connections.push(legacy);
  legacy.version(1).stores(schemaV1);
  await legacy.open();
  await legacy.table('metadata').put({
    key: 'cache',
    ...scope,
    schemaVersion: 1,
    createdAt: '2026-10-09T00:00:00Z',
    lastCachedAt: null,
  });
  const intent = pending();
  await legacy
    .table('pendingRecords')
    .put({ operationId: intent.operationId, value: intent.localValue });
  legacy.close();
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const put = IDBObjectStore.prototype.put;
  const fault = vi.spyOn(IDBObjectStore.prototype, 'put').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'metadata') throw new DOMException('Full', 'QuotaExceededError');
    return put.apply(this, args);
  });
  await expect(open()).rejects.toMatchObject({ code: 'quota' });
  fault.mockRestore();
  await legacy.open();
  expect(legacy.backendDB().version).toBe(10);
  expect((await legacy.table('metadata').get('cache')).schemaVersion).toBe(1);
  expect(await legacy.table('pendingRecords').count()).toBe(1);
  legacy.close();
  const upgraded = await open();
  expect(await upgraded.pending.records()).toHaveLength(1);
});

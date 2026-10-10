import 'fake-indexeddb/auto';
import { Dexie } from 'dexie';
import { IDBObjectStore } from 'fake-indexeddb';
import { afterEach, expect, it, vi } from 'vitest';
import contract from '../../../../docs/contracts/sync.example.json';
import { databaseName } from '../database/database';
import { openAccountStorage, type AccountStorage } from './accountStorage';
import { stageProfileUpdate, type PullPage } from '../sync/protocol';

const scope = { accountId: 81, organizationId: contract.accepted.profile.organization_id };
const stores: AccountStorage[] = [];
const now = '2026-10-09T00:00:00Z';

it('discards only explicitly selected terminal intent and preserves confirmed records', async () => {
  const storage = await open();
  await storage.sync.applyPage(null, page('first', 2), now);
  const operation = await stageProfileUpdate(storage, contract.operation.record_id, {
    expected_revision: 1,
    display_name: 'Conflicting intent',
  });
  await expect(storage.sync.discardTerminal(operation.operation_id)).rejects.toMatchObject({
    code: 'write_failed',
  });
  await storage.sync.mark(operation.operation_id, 'conflict');
  expect((await storage.sync.snapshot()).commands).toHaveLength(1);
  await storage.sync.discardTerminal(operation.operation_id);
  expect(await storage.pending.list()).toEqual([]);
  expect(await storage.pending.records()).toEqual([]);
  expect((await storage.profiles.get(contract.operation.record_id))?.value.revision).toBe(2);
});

it('rolls back explicit discard if deleting the local representation fails', async () => {
  const storage = await open();
  const operation = await stageProfileUpdate(storage, contract.operation.record_id, {
    expected_revision: 1,
    display_name: 'Keep on failure',
  });
  await storage.sync.mark(operation.operation_id, 'rejected');
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const remove = IDBObjectStore.prototype.delete;
  vi.spyOn(IDBObjectStore.prototype, 'delete').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'pendingRecords') throw new Error('Storage failure');
    return remove.apply(this, args);
  });
  await expect(storage.sync.discardTerminal(operation.operation_id)).rejects.toThrow();
  expect(await storage.pending.list()).toHaveLength(1);
  expect(await storage.pending.records()).toHaveLength(1);
});
async function open(accountId = scope.accountId) {
  const storage = await openAccountStorage({ ...scope, accountId });
  stores.push(storage);
  return storage;
}
const page = (token: string, revision: number | null): PullPage => ({
  checkpoint: token,
  has_more: false,
  changes: [
    {
      sequence: 1,
      record_type: 'member_profile',
      record_id: contract.operation.record_id,
      value: revision === null ? null : { ...contract.accepted.profile, revision },
    },
  ],
});
afterEach(async () => {
  vi.restoreAllMocks();
  for (const storage of stores.splice(0)) {
    storage.close();
    await Dexie.delete(databaseName(storage.scope));
  }
});

it('rolls back record changes when writing the checkpoint fails', async () => {
  const storage = await open();
  await storage.sync.applyPage(null, page('first', 2), now);
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const put = IDBObjectStore.prototype.put;
  vi.spyOn(IDBObjectStore.prototype, 'put').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'checkpoints') throw new DOMException('Full', 'QuotaExceededError');
    return put.apply(this, args);
  });
  await expect(storage.sync.applyPage('first', page('second', null), now)).rejects.toMatchObject({
    code: 'quota',
  });
  expect((await storage.profiles.get(contract.operation.record_id))?.value.revision).toBe(2);
  expect((await storage.checkpoints.get('profiles'))?.token).toBe('first');
});

it('rejects stale pages and resets without resurrecting tombstones or rolling back checkpoints', async () => {
  const storage = await open();
  await storage.sync.applyPage(null, page('a', 2), now);
  await storage.sync.applyPage('a', page('b', null), now);
  expect(await storage.sync.applyPage('a', page('late', 2), now)).toBe(false);
  expect(await storage.sync.reset('a')).toBe(false);
  expect(await storage.profiles.list()).toEqual([]);
  expect((await storage.checkpoints.get('profiles'))?.token).toBe('b');
});

it('never replaces higher domain revisions and never applies replay receipts as current data', async () => {
  const storage = await open();
  await storage.sync.applyPage(null, page('a', 10), now);
  await storage.sync.applyPage('a', page('b', 2), now);
  expect((await storage.profiles.get(contract.operation.record_id))?.value.revision).toBe(10);
  await storage.sync.applyPage('b', page('c', null), now);
  const operation = await stageProfileUpdate(storage, contract.operation.record_id, {
    expected_revision: 1,
    display_name: 'Lost response',
  });
  await storage.sync.settle({
    ...contract.accepted,
    status: 'accepted',
    operation_id: operation.operation_id,
  });
  expect(await storage.profiles.list()).toEqual([]);
  expect(await storage.pending.list()).toEqual([]);
});

it('settles commands and projections atomically even if the second deletion fails', async () => {
  const storage = await open();
  const operation = await stageProfileUpdate(storage, contract.operation.record_id, {
    expected_revision: 1,
    display_name: 'Preserve me',
  });
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const remove = IDBObjectStore.prototype.delete;
  vi.spyOn(IDBObjectStore.prototype, 'delete').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'pendingRecords') throw new DOMException('Failure', 'UnknownError');
    return remove.apply(this, args);
  });
  await expect(
    storage.sync.settle({
      ...contract.accepted,
      status: 'accepted',
      operation_id: operation.operation_id,
    }),
  ).rejects.toMatchObject({ code: 'write_failed' });
  expect(await storage.pending.list()).toHaveLength(1);
  expect(await storage.pending.records()).toHaveLength(1);
});

it('isolates persisted coordinator state by account and clears it with cache removal', async () => {
  const a = await open();
  const b = await open(82);
  await a.sync.saveState({
    ...(await a.sync.state()),
    paused: true,
    failures: 3,
    problem: { code: 'access', message: 'Sign in' },
  });
  expect(await b.sync.state()).toMatchObject({ paused: false, failures: 0 });
  await a.clearCache();
  expect(await a.sync.state()).toMatchObject({ paused: false, failures: 0 });
});

it('rejects malformed and foreign data before any page writes', async () => {
  const storage = await open();
  const foreign = page('bad', 2);
  foreign.changes[0]!.value!.organization_id = crypto.randomUUID();
  await expect(storage.sync.applyPage(null, foreign, now)).rejects.toMatchObject({
    code: 'invalid_response',
  });
  expect(await storage.profiles.list()).toEqual([]);
  expect(await storage.checkpoints.get('profiles')).toBeUndefined();
});

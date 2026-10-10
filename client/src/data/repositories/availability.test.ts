import 'fake-indexeddb/auto';
import { Dexie } from 'dexie';
import { afterEach, expect, it, vi } from 'vitest';
import { IDBObjectStore } from 'fake-indexeddb';
import { openAccountStorage, type AccountStorage } from './accountStorage';
import { databaseName, schemaV2 } from '../database/database';
import { SyncCoordinator, type SyncEnvironment } from '../sync/coordinator';
import { parseOperation, parseResult, pendingOperation, stageAvailability } from '../sync/protocol';
import { offlineWorkspace } from './offlineWorkspace';
import { parseUnavailability } from '../api/availability';

const scope = { accountId: 19, organizationId: '01920000-0000-7000-8000-000000000002' };
const input = {
  membership_id: '01920000-0000-7000-8000-000000000003',
  starts_at: '2026-11-01T07:00:00Z',
  ends_at: '2026-11-01T12:00:00Z',
};
const connections: AccountStorage[] = [];
async function open() {
  const storage = await openAccountStorage(scope);
  connections.push(storage);
  return storage;
}
afterEach(async () => {
  vi.restoreAllMocks();
  connections.splice(0).forEach((storage) => storage.close());
  await Dexie.delete(databaseName(scope));
  await Dexie.delete('rush:workspace');
});

it('retains accepted local intent across interrupted pull and atomically replaces it with confirmed data', async () => {
  let storage = await open();
  const operation = await stageAvailability(storage, input);
  const value = {
    ...input,
    id: operation.record_id,
    organization_id: scope.organizationId,
    revision: 1,
  };
  await storage.sync.settle({
    operation_id: operation.operation_id,
    status: 'accepted',
    unavailability: value,
  });
  storage.close();
  storage = await open();
  expect((await storage.pending.list())[0]?.acceptedRevision).toBe(1);
  expect((await storage.pending.records())[0]?.value).toEqual(input);
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const put = IDBObjectStore.prototype.put;
  const failure = vi.spyOn(IDBObjectStore.prototype, 'put').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'checkpoints') throw new DOMException('Full', 'QuotaExceededError');
    return put.apply(this, args);
  });
  const page = {
    changes: [{ sequence: 1, record_type: 'unavailability' as const, record_id: value.id, value }],
    checkpoint: 'accepted',
    has_more: false,
  };
  await expect(storage.sync.applyPage(null, page, 'now')).rejects.toMatchObject({ code: 'quota' });
  expect(await storage.pending.records()).toHaveLength(1);
  expect(await storage.availability.list()).toEqual([]);
  failure.mockRestore();
  await storage.sync.applyPage(null, page, 'now');
  expect(await storage.pending.list()).toEqual([]);
  expect(await storage.pending.records()).toEqual([]);
  expect((await storage.availability.list())[0]?.value).toEqual(value);
});
const env: SyncEnvironment = {
  online: () => true,
  now: () => 1000,
  random: () => 0,
  subscribe: () => () => {},
  exclusive: async (_name, _signal, work) => {
    await work();
  },
};

it('reopens offline intent then reconciles the same operation ID through the coordinator', async () => {
  let storage = await open();
  const operation = await stageAvailability(storage, input);
  storage.close();
  storage = await open();
  expect(pendingOperation((await storage.pending.list())[0]!)).toEqual(operation);
  expect((await storage.pending.records())[0]?.value).toEqual(input);
  const record = {
    id: operation.record_id,
    organization_id: scope.organizationId,
    ...input,
    revision: 1,
  };
  const coordinator = new SyncCoordinator(storage, env, {
    push: (_org, received) => {
      expect(received).toEqual(operation);
      return Promise.resolve({
        operation_id: operation.operation_id,
        status: 'accepted',
        unavailability: record,
      });
    },
    pull: () =>
      Promise.resolve({
        changes: [
          { sequence: 1, record_type: 'unavailability', record_id: record.id, value: record },
        ],
        checkpoint: 'accepted',
        has_more: false,
      }),
  });
  await coordinator.syncOnce();
  expect(await storage.pending.list()).toEqual([]);
  expect((await storage.availability.list())[0]?.value).toEqual(record);
  storage.close();
  storage = await open();
  expect((await storage.availability.list())[0]?.value).toEqual(record);
});

it.each(['rejected', 'conflict'] as const)(
  'preserves %s intent beside the current Server revision',
  async (status) => {
    const storage = await open();
    const operation = await stageAvailability(storage, input, crypto.randomUUID(), 1);
    const current = {
      ...input,
      id: operation.record_id,
      organization_id: scope.organizationId,
      revision: 2,
      ends_at: '2026-11-01T13:00:00Z',
    };
    const coordinator = new SyncCoordinator(storage, env, {
      push: () =>
        Promise.resolve({
          operation_id: operation.operation_id,
          status,
          error: { code: 'review', message: 'Review this range.', errors: {} },
        }),
      pull: () =>
        Promise.resolve({
          changes: [
            { sequence: 1, record_type: 'unavailability', record_id: current.id, value: current },
          ],
          checkpoint: 'new',
          has_more: false,
        }),
    });
    await coordinator.syncOnce();
    expect((await storage.pending.list())[0]?.state).toBe(status);
    expect((await storage.pending.records())[0]?.value).toEqual(input);
    expect((await storage.availability.list())[0]?.value).toEqual(current);
  },
);

it('does not acknowledge invalid intervals locally or accept foreign Server data', async () => {
  const storage = await open();
  await expect(stageAvailability(storage, { ...input, ends_at: input.starts_at })).rejects.toThrow(
    'after',
  );
  expect(await storage.pending.list()).toEqual([]);
  const op = await stageAvailability(storage, input);
  const value = { id: op.record_id, organization_id: scope.organizationId, ...input, revision: 1 };
  expect(() =>
    parseResult(
      {
        operation_id: op.operation_id,
        status: 'accepted',
        unavailability: { ...value, membership_id: crypto.randomUUID() },
      },
      scope.organizationId,
      op,
    ),
  ).toThrow();
  expect(() =>
    parseUnavailability(
      { ...value, organization_id: crypto.randomUUID() },
      scope.organizationId,
      op.record_id,
    ),
  ).toThrow();
  expect(() => parseOperation({ ...op, expected_revision: 0 })).toThrow();
});

it('migrates a released v2 partition without losing pending data or checkpoint', async () => {
  const legacy = new Dexie(databaseName(scope));
  legacy.version(2).stores(schemaV2);
  await legacy.open();
  await legacy.table('metadata').put({
    key: 'cache',
    ...scope,
    schemaVersion: 2,
    createdAt: '2026-10-09T00:00:00Z',
    lastCachedAt: null,
  });
  await legacy.table('pendingRecords').put({ operationId: 'old', value: { note: 'Keep' } });
  await legacy.table('checkpoints').put({ stream: 'profiles', token: 'old-checkpoint' });
  legacy.close();
  const storage = await open();
  expect(await storage.pending.records()).toMatchObject([{ value: { note: 'Keep' } }]);
  expect((await storage.checkpoints.get('profiles'))?.token).toBe('old-checkpoint');
  expect(await storage.availability.list()).toEqual([]);
  expect((await storage.metadata.get())?.schemaVersion).toBe(3);
});

it('retains only a scope locator in the offline workspace directory', async () => {
  const value = {
    key: 'active' as const,
    ...scope,
    membershipId: input.membership_id,
    label: 'Rangers',
  };
  await offlineWorkspace.put({ ...value, secret: 'must not persist' } as typeof value);
  expect(await offlineWorkspace.get()).toEqual(value);
  await offlineWorkspace.clear();
  expect(await offlineWorkspace.get()).toBeUndefined();
});

it('applies availability tombstones and clears confirmed ranges on checkpoint reset while preserving intent', async () => {
  const storage = await open();
  const operation = await stageAvailability(storage, input);
  const record = {
    ...input,
    id: operation.record_id,
    organization_id: scope.organizationId,
    revision: 1,
  };
  await storage.sync.applyPage(
    null,
    {
      changes: [
        { sequence: 1, record_type: 'unavailability', record_id: record.id, value: record },
      ],
      checkpoint: 'one',
      has_more: false,
    },
    'now',
  );
  await storage.sync.applyPage(
    'one',
    {
      changes: [{ sequence: 2, record_type: 'unavailability', record_id: record.id, value: null }],
      checkpoint: 'two',
      has_more: false,
    },
    'now',
  );
  expect(await storage.availability.list()).toEqual([]);
  await storage.sync.reset('two');
  expect(await storage.pending.list()).toHaveLength(1);
});

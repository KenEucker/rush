import 'fake-indexeddb/auto';
import { reactive } from 'vue';
import { Dexie } from 'dexie';
import { IDBObjectStore } from 'fake-indexeddb';
import { afterEach, expect, it, vi } from 'vitest';
import { openAccountStorage, type AccountStorage } from './accountStorage';
import { databaseName, schemaV3 } from '../database/database';
import {
  parseOperation,
  parsePullPage,
  parseResult,
  pendingOperation,
  stagePreference,
} from '../sync/protocol';
import { SyncCoordinator, type SyncEnvironment } from '../sync/coordinator';
import type { PreferenceInput } from '../api/preferences';
import { effectivePreferences, weekStart } from '../../domain/preferences';

const scope = { accountId: 191, organizationId: '01920000-0000-7000-8000-000000000002' };
const input: PreferenceInput = {
  membership_id: '01920000-0000-7000-8000-000000000003',
  season_id: null,
  week_starts_on: null,
  preferred_shift_ids: [],
  hours_mode: 'target',
  desired_weekly_minutes: 1920,
  days_off: 'consecutive',
};
const connections: AccountStorage[] = [];
async function open() {
  const db = await openAccountStorage(scope);
  connections.push(db);
  return db;
}
afterEach(async () => {
  vi.restoreAllMocks();
  connections.splice(0).forEach((db) => db.close());
  await Dexie.delete(databaseName(scope));
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

it('survives offline reload and interrupted acceptance then reconciles through the shared coordinator', async () => {
  let storage = await open();
  const op = await stagePreference(storage, reactive({ ...input, preferred_shift_ids: [] }));
  storage.close();
  storage = await open();
  expect(pendingOperation((await storage.pending.list())[0]!)).toEqual(op);
  const profile = {
    ...input,
    id: op.record_id,
    organization_id: scope.organizationId,
    revision: 1,
  };
  await storage.sync.settle({
    operation_id: op.operation_id,
    status: 'accepted',
    preference_profile: profile,
  });
  storage.close();
  storage = await open();
  expect((await storage.pending.records())[0]?.value).toEqual(input);
  const coordinator = new SyncCoordinator(storage, env, {
    push: (_org, received) => {
      expect(received).toEqual(op);
      return Promise.resolve({
        operation_id: op.operation_id,
        status: 'accepted',
        preference_profile: profile,
      });
    },
    pull: () =>
      Promise.resolve({
        changes: [
          { sequence: 1, record_type: 'preference_profile', record_id: profile.id, value: profile },
        ],
        checkpoint: 'accepted',
        has_more: false,
      }),
  });
  await coordinator.syncOnce();
  expect(await storage.pending.list()).toEqual([]);
  expect((await storage.preferences.list())[0]?.value).toEqual(profile);
  storage.close();
  storage = await open();
  expect((await storage.preferences.list())[0]?.value).toEqual(profile);
});

it.each(['conflict', 'rejected'] as const)(
  'retains %s intent beside the Server data through reset and reopen',
  async (status) => {
    let storage = await open();
    const op = await stagePreference(storage, input, crypto.randomUUID(), 1);
    const profile = {
      ...input,
      days_off: 'separated' as const,
      id: op.record_id,
      organization_id: scope.organizationId,
      revision: 2,
    };
    await storage.sync.settle({
      operation_id: op.operation_id,
      status,
      error: { code: 'review', message: 'Review preferences', errors: {} },
    });
    await storage.sync.applyPage(
      null,
      {
        changes: [
          { sequence: 1, record_type: 'preference_profile', record_id: profile.id, value: profile },
        ],
        checkpoint: 'new',
        has_more: false,
      },
      'now',
    );
    expect((await storage.pending.records())[0]?.value).toEqual(input);
    expect((await storage.preferences.list())[0]?.value).toEqual(profile);
    await storage.sync.reset('new');
    storage.close();
    storage = await open();
    expect((await storage.pending.list())[0]?.state).toBe(status);
    expect(await storage.preferences.list()).toEqual([]);
    await expect(storage.clearCache()).rejects.toMatchObject({ code: 'pending_work' });
  },
);

it('rolls back both intent writes and pull reconciliation on storage failure', async () => {
  const storage = await open();
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const add = IDBObjectStore.prototype.add;
  const failure = vi.spyOn(IDBObjectStore.prototype, 'add').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'pendingRecords') throw new DOMException('Full', 'QuotaExceededError');
    return add.apply(this, args);
  });
  await expect(stagePreference(storage, input)).rejects.toMatchObject({ code: 'quota' });
  expect(await storage.pending.list()).toEqual([]);
  failure.mockRestore();
  const op = await stagePreference(storage, input);
  const value = { ...input, id: op.record_id, organization_id: scope.organizationId, revision: 1 };
  await storage.sync.settle({
    operation_id: op.operation_id,
    status: 'accepted',
    preference_profile: value,
  });
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const put = IDBObjectStore.prototype.put;
  const pullFailure = vi.spyOn(IDBObjectStore.prototype, 'put').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'checkpoints') throw new DOMException('Full', 'QuotaExceededError');
    return put.apply(this, args);
  });
  await expect(
    storage.sync.applyPage(
      null,
      {
        changes: [{ sequence: 1, record_type: 'preference_profile', record_id: value.id, value }],
        checkpoint: 'x',
        has_more: false,
      },
      'now',
    ),
  ).rejects.toMatchObject({ code: 'quota' });
  expect(await storage.pending.list()).toHaveLength(1);
  expect(await storage.preferences.list()).toEqual([]);
  pullFailure.mockRestore();
});

it('upgrades v3 without losing pending work or the checkpoint and clears cached catalogs on reset', async () => {
  const legacy = new Dexie(databaseName(scope));
  legacy.version(3).stores(schemaV3);
  await legacy.open();
  await legacy
    .table('metadata')
    .put({ key: 'cache', ...scope, schemaVersion: 3, createdAt: 'now', lastCachedAt: null });
  await legacy
    .table('pendingRecords')
    .put({ operationId: crypto.randomUUID(), value: { starts_at: 'saved' } });
  await legacy.table('checkpoints').put({ stream: 'profiles', token: 'old' });
  legacy.close();
  const storage = await open();
  expect((await storage.metadata.get())?.schemaVersion).toBe(4);
  expect(await storage.pending.records()).toHaveLength(1);
  expect((await storage.checkpoints.get('profiles'))?.token).toBe('old');
  const id = crypto.randomUUID();
  const catalog = {
    id,
    organization_id: scope.organizationId,
    name: 'Season',
    starts_on: '2026-10-01',
    ends_on: '2026-12-31',
    timezone: 'America/Los_Angeles',
    week_starts_on: 1,
    shifts: [],
  };
  await storage.sync.applyPage(
    'old',
    {
      changes: [{ sequence: 1, record_type: 'preference_catalog', record_id: id, value: catalog }],
      checkpoint: 'new',
      has_more: false,
    },
    'now',
  );
  expect((await storage.preferences.catalogs())[0]?.value).toEqual(catalog);
  await storage.clearConfirmed();
  expect(await storage.preferences.catalogs()).toEqual([]);
  expect(await storage.pending.records()).toHaveLength(1);
});

it('distinguishes inheritance empty lists neutral values and current default hours', () => {
  const weekly = {
    ...input,
    preferred_shift_ids: null,
    hours_mode: null,
    desired_weekly_minutes: null,
    days_off: 'none' as const,
  };
  expect(effectivePreferences(input, weekly)).toMatchObject({
    desired_weekly_minutes: 1920,
    days_off: 'none',
  });
  expect(
    effectivePreferences({ ...input, desired_weekly_minutes: 1440 }, weekly).desired_weekly_minutes,
  ).toBe(1440);
  expect(
    effectivePreferences(input, { ...weekly, preferred_shift_ids: [], hours_mode: 'maximum' }),
  ).toMatchObject({ preferred_shift_ids: [], hours_mode: 'maximum', desired_weekly_minutes: null });
  expect(weekStart('2027-01-01', 1)).toBe('2026-12-28');
  expect(weekStart('2026-11-01', 1)).toBe('2026-10-26');
  expect(weekStart('2026-03-08', 7)).toBe('2026-03-08');
});

it('atomically replaces reviewed conflict intent and preserves it if the replacement fails', async () => {
  const storage = await open();
  const old = await stagePreference(storage, input, crypto.randomUUID(), 1);
  await storage.sync.mark(old.operation_id, 'conflict', {
    code: 'revision_conflict',
    message: 'Review',
  });
  // eslint-disable-next-line @typescript-eslint/unbound-method
  const add = IDBObjectStore.prototype.add;
  const failure = vi.spyOn(IDBObjectStore.prototype, 'add').mockImplementation(function (
    this: IDBObjectStore,
    ...args
  ) {
    if (this.name === 'pendingRecords') throw new DOMException('Full', 'QuotaExceededError');
    return add.apply(this, args);
  });
  await expect(
    stagePreference(storage, input, old.record_id, 2, old.operation_id),
  ).rejects.toMatchObject({ code: 'quota' });
  expect((await storage.preferences.snapshot()).commands).toMatchObject([
    { operationId: old.operation_id, state: 'conflict' },
  ]);
  failure.mockRestore();
  const replacement = await stagePreference(storage, input, old.record_id, 2, old.operation_id);
  const snapshot = await storage.preferences.snapshot();
  expect(snapshot.commands).toMatchObject([
    { operationId: replacement.operation_id, expectedRevision: 2, state: 'pending' },
  ]);
  expect(snapshot.local).toHaveLength(1);
  await expect(
    stagePreference(storage, input, old.record_id, 2, replacement.operation_id),
  ).rejects.toThrow();
  expect(await storage.pending.list()).toHaveLength(1);
});

it('rejects malformed wire data cross-account receipts and unsupported fields before persistence', async () => {
  const storage = await open();
  const op = await stagePreference(storage, input);
  expect(() => parseOperation({ ...op, payload: { ...input, days_off: null } })).toThrow();
  expect(() => parseOperation({ ...op, payload: { ...input, hours_mode: 'maximum' } })).toThrow();
  expect(() =>
    parseResult(
      {
        operation_id: op.operation_id,
        status: 'accepted',
        preference_profile: {
          ...input,
          id: op.record_id,
          organization_id: crypto.randomUUID(),
          revision: 1,
        },
      },
      scope.organizationId,
      op,
    ),
  ).toThrow();
  expect(() =>
    parsePullPage(
      {
        changes: [
          {
            sequence: 1,
            record_type: 'preference_catalog',
            record_id: crypto.randomUUID(),
            value: {},
          },
        ],
        checkpoint: 'x',
        has_more: false,
      },
      scope.organizationId,
    ),
  ).toThrow();
});

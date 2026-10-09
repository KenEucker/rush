import 'fake-indexeddb/auto';
import { Dexie } from 'dexie';
import { afterEach, expect, it, vi } from 'vitest';
import contract from '../../../../docs/contracts/sync.example.json';
import { ApiError } from '../api/http';
import { databaseName } from '../database/database';
import { openAccountStorage, type AccountStorage } from '../repositories/accountStorage';
import { SyncCoordinator, type SyncEnvironment, type SyncTransport } from './coordinator';
import type { PullPage, SyncOperation } from './protocol';

const scope = { accountId: 1, organizationId: contract.accepted.profile.organization_id };
const stores: AccountStorage[] = [];
const coordinators: SyncCoordinator[] = [];
const locks = new Map<string, Promise<void>>();
let clock = Date.parse('2026-10-09T12:00:00Z');
let online = true;
const listeners = new Set<() => void>();
const environment: SyncEnvironment = {
  online: () => online,
  now: () => clock,
  random: () => 1,
  subscribe(wake) {
    listeners.add(wake);
    return () => {
      listeners.delete(wake);
    };
  },
  exclusive(name, signal, work) {
    const next = (locks.get(name) ?? Promise.resolve()).then(async () => {
      if (!signal.aborted) await work();
    });
    locks.set(
      name,
      next.catch(() => {}),
    );
    return next;
  },
};
const page = (
  checkpoint = 'end',
  changes: PullPage['changes'] = [],
  has_more = false,
): PullPage => ({ checkpoint, changes, has_more });
const change = (revision: number, sequence = revision) => ({
  sequence,
  record_type: 'member_profile' as const,
  record_id: contract.operation.record_id,
  value: { ...contract.accepted.profile, revision },
});
const accepted = (operation: SyncOperation) => ({
  ...contract.accepted,
  operation_id: operation.operation_id,
  status: 'accepted' as const,
});
function api() {
  return {
    push: vi
      .fn<SyncTransport['push']>()
      .mockImplementation((_org, operation) => Promise.resolve(accepted(operation))),
    pull: vi.fn<SyncTransport['pull']>().mockResolvedValue(page()),
  };
}
async function open() {
  const storage = await openAccountStorage(scope);
  stores.push(storage);
  return storage;
}
function coordinator(storage: AccountStorage, transport = api()) {
  const sync = new SyncCoordinator(storage, environment, transport);
  coordinators.push(sync);
  return sync;
}
async function stage(sync: SyncCoordinator) {
  return sync.queueProfileUpdate(contract.operation.record_id, {
    expected_revision: 1,
    display_name: 'Keep my offline intent',
    phone: null,
  });
}
afterEach(async () => {
  await Promise.all(coordinators.splice(0).map((sync) => sync.stop()));
  vi.useRealTimers();
  vi.restoreAllMocks();
  stores.splice(0).forEach((storage) => storage.close());
  await Dexie.delete(databaseName(scope));
  locks.clear();
  listeners.clear();
  online = true;
  clock = Date.parse('2026-10-09T12:00:00Z');
});

it('acknowledges only durable writes and reconciles after offline reload', async () => {
  online = false;
  let storage = await open();
  const transport = api();
  let sync = coordinator(storage, transport);
  const operation = await stage(sync);
  expect(await storage.pending.records()).toHaveLength(1);
  expect(await storage.profiles.list()).toEqual([]);
  await sync.syncOnce();
  expect(transport.push).not.toHaveBeenCalled();
  await sync.stop();
  storage.close();
  storage = await open();
  sync = coordinator(storage, transport);
  online = true;
  transport.pull.mockResolvedValue(page('new', [change(2)]));
  await sync.syncOnce();
  expect(transport.push.mock.calls[0]?.[1]).toEqual(operation);
  expect(await storage.pending.list()).toEqual([]);
  expect(await storage.pending.records()).toEqual([]);
  expect((await storage.profiles.get(operation.record_id))?.value.revision).toBe(2);
  expect(await storage.sync.state()).toMatchObject({
    failures: 0,
    lastSuccessfulSyncAt: new Date(clock).toISOString(),
  });
  storage.close();
  await expect(stage(sync)).rejects.toMatchObject({ code: 'closed' });
});

it('persists backoff after a lost accepted response and replays exactly the same ID after restart', async () => {
  let storage = await open();
  const transport = api();
  const receipts = new Set<string>();
  transport.push.mockImplementation((_org, operation) => {
    const replay = receipts.has(operation.operation_id);
    receipts.add(operation.operation_id); // Simulated Server receipt commits before disconnect.
    return replay
      ? Promise.resolve(accepted(operation))
      : Promise.reject(new TypeError('Connection lost'));
  });
  let sync = coordinator(storage, transport);
  const operation = await stage(sync);
  await sync.syncOnce();
  expect(await storage.pending.list()).toMatchObject([{ state: 'failed', attempts: 1 }]);
  expect(await storage.sync.state()).toMatchObject({
    nextAttemptAt: clock + 1000,
    failures: 1,
    paused: false,
  });
  await sync.stop();
  storage.close();
  storage = await open();
  sync = coordinator(storage, transport);
  await sync.syncOnce();
  expect(transport.push).toHaveBeenCalledTimes(1);
  clock += 1000;
  await sync.syncOnce();
  expect(transport.push).toHaveBeenCalledTimes(2);
  expect(transport.push.mock.calls[1]?.[1]).toEqual(operation);
  expect(receipts.size).toBe(1);
  expect(await storage.pending.list()).toEqual([]);
});

it('resumes a syncing command left by an interrupted process', async () => {
  const storage = await open();
  const transport = api();
  const sync = coordinator(storage, transport);
  const operation = await stage(sync);
  await storage.sync.mark(operation.operation_id, 'syncing');
  await sync.syncOnce();
  expect(transport.push.mock.calls[0]?.[1]).toEqual(operation);
  expect(await storage.pending.records()).toEqual([]);
});

it.each([401, 403, 419])(
  'pauses on HTTP %s across restart until explicit session recovery',
  async (status) => {
    let storage = await open();
    const transport = api();
    transport.push.mockRejectedValueOnce(
      new ApiError(status, 'Sign in or review access', 'access'),
    );
    let sync = coordinator(storage, transport);
    await stage(sync);
    await sync.syncOnce();
    expect(await storage.sync.state()).toMatchObject({ paused: true, problem: { code: 'access' } });
    expect(await storage.pending.records()).toHaveLength(1);
    await sync.stop();
    storage.close();
    storage = await open();
    sync = coordinator(storage, transport);
    clock += 600_000;
    await sync.syncOnce();
    expect(transport.push).toHaveBeenCalledTimes(1);
    await sync.resumeAfterAuthentication();
    expect(transport.push).toHaveBeenCalledTimes(2);
    expect(await storage.pending.list()).toEqual([]);
  },
);

it.each([429, 503])(
  'backs off temporary HTTP %s failures exponentially without dropping intent',
  async (status) => {
    const storage = await open();
    const transport = api();
    transport.push.mockRejectedValue(new ApiError(status, 'Try later'));
    const sync = coordinator(storage, transport);
    await stage(sync);
    for (let attempt = 1; attempt <= 11; attempt++) {
      await sync.syncOnce();
      const state = await storage.sync.state();
      expect(state).toMatchObject({ failures: attempt, paused: false });
      expect(state.nextAttemptAt! - clock).toBe(Math.min(300_000, 1000 * 2 ** (attempt - 1)));
      clock = state.nextAttemptAt!;
    }
    expect(await storage.pending.records()).toHaveLength(1);
  },
);

it.each(['conflict', 'rejected'] as const)(
  'retains %s intent and never automatically retries it',
  async (status) => {
    const storage = await open();
    const transport = api();
    transport.push.mockImplementation((_org, operation) =>
      Promise.resolve({
        operation_id: operation.operation_id,
        status,
        error: { code: 'review_required', message: 'Review saved intent', errors: {} },
      }),
    );
    const sync = coordinator(storage, transport);
    const operation = await stage(sync);
    await sync.syncOnce();
    await sync.syncOnce();
    expect(transport.push).toHaveBeenCalledTimes(1);
    expect(await storage.pending.list()).toMatchObject([
      { state: status, problem: { message: 'Review saved intent' } },
    ]);
    expect(await storage.pending.records()).toMatchObject([{ value: operation.payload }]);
  },
);

it.each([404, 409, 422])(
  'preserves terminal HTTP %s command failures while still pulling',
  async (status) => {
    const storage = await open();
    const transport = api();
    transport.push.mockRejectedValue(new ApiError(status, 'Review this change', 'terminal'));
    const sync = coordinator(storage, transport);
    await stage(sync);
    await sync.syncOnce();
    await sync.syncOnce();
    expect(transport.push).toHaveBeenCalledTimes(1);
    expect(transport.pull).toHaveBeenCalledTimes(2);
    expect(await storage.pending.list()).toMatchObject([
      { state: status === 409 ? 'conflict' : 'rejected' },
    ]);
  },
);

it('resumes a paginated pull at its committed checkpoint following interruption', async () => {
  const storage = await open();
  const transport = api();
  transport.pull
    .mockResolvedValueOnce(page('part', [change(2)], true))
    .mockRejectedValueOnce(new TypeError('Offline'));
  const sync = coordinator(storage, transport);
  await sync.syncOnce();
  expect(await storage.checkpoints.get('profiles')).toMatchObject({
    token: 'part',
    lastSuccessfulSyncAt: null,
  });
  expect((await storage.sync.state()).lastSuccessfulSyncAt).toBeNull();
  clock += 1000;
  transport.pull.mockResolvedValue(page('done', [{ ...change(3), value: null }]));
  await sync.syncOnce();
  expect(transport.pull.mock.calls.map((call) => call[1])).toEqual([null, 'part', 'part']);
  expect(await storage.profiles.list()).toEqual([]);
  expect((await storage.checkpoints.get('profiles'))?.token).toBe('done');
});

it('resets invalid checkpoints and private confirmed cache while preserving conflicted intent', async () => {
  const storage = await open();
  const transport = api();
  const sync = coordinator(storage, transport);
  const operation = await stage(sync);
  await storage.sync.mark(operation.operation_id, 'conflict', {
    code: 'revision_conflict',
    message: 'Review',
  });
  await storage.profiles.cache([contract.accepted.profile]);
  await storage.checkpoints.put({
    stream: 'profiles',
    token: 'invalid',
    lastSuccessfulSyncAt: new Date(clock).toISOString(),
  });
  transport.pull
    .mockRejectedValueOnce(new ApiError(409, 'Reset', 'checkpoint_invalid'))
    .mockImplementationOnce(async (_org, checkpoint) => {
      expect(checkpoint).toBeNull();
      expect(await storage.profiles.list()).toEqual([]);
      expect(await storage.pending.records()).toHaveLength(1);
      return page('authorized');
    });
  await sync.syncOnce();
  expect((await storage.checkpoints.get('profiles'))?.token).toBe('authorized');
  expect(await storage.pending.list()).toMatchObject([{ state: 'conflict' }]);
});

it('bounds repeated invalid-checkpoint recovery and persists its failure', async () => {
  const storage = await open();
  const transport = api();
  transport.pull.mockRejectedValue(new ApiError(409, 'Reset', 'checkpoint_invalid'));
  await coordinator(storage, transport).syncOnce();
  expect(transport.pull).toHaveBeenCalledTimes(2);
  expect(await storage.sync.state()).toMatchObject({
    paused: true,
    problem: { code: 'checkpoint_invalid' },
  });
});

it('serializes two connections and coalesces simultaneous triggers without duplicate pushes or stale pulls', async () => {
  const storage = await open();
  const other = await open();
  const transport = api();
  let active = 0;
  let maximum = 0;
  transport.push.mockImplementation(async (_org, operation) => {
    maximum = Math.max(maximum, ++active);
    await new Promise((resolve) => setTimeout(resolve, 5));
    active--;
    return accepted(operation);
  });
  transport.pull.mockImplementation(async (_org, checkpoint) => {
    maximum = Math.max(maximum, ++active);
    await new Promise((resolve) => setTimeout(resolve, 5));
    active--;
    return page(checkpoint === null ? 'first' : 'second');
  });
  const a = coordinator(storage, transport);
  const b = coordinator(other, transport);
  await stage(a);
  await Promise.all([a.syncOnce(), a.syncOnce(), b.syncOnce()]);
  expect(maximum).toBe(1);
  expect(transport.push).toHaveBeenCalledTimes(1);
  expect(transport.pull.mock.calls.map((call) => call[1])).toEqual([null, 'first']);
});

it('ignores a late response after stop and safely replays its syncing operation', async () => {
  const storage = await open();
  const transport = api();
  let release!: () => void;
  transport.push.mockImplementationOnce(async (_org, operation) => {
    await new Promise<void>((resolve) => {
      release = resolve;
    });
    return accepted(operation);
  });
  const sync = coordinator(storage, transport);
  await stage(sync);
  const flight = sync.syncOnce();
  await vi.waitFor(() => expect(release).toBeDefined());
  const stopped = sync.stop();
  release();
  await Promise.all([flight, stopped]);
  expect(await storage.pending.list()).toMatchObject([{ state: 'syncing' }]);
  expect(transport.pull).not.toHaveBeenCalled();
  await coordinator(storage, transport).syncOnce();
  expect(await storage.pending.list()).toEqual([]);
});

it('wakes on foreground connectivity and removes listeners on stop', async () => {
  const storage = await open();
  const transport = api();
  const sync = coordinator(storage, transport);
  online = false;
  await sync.start();
  await stage(sync);
  expect(transport.push).not.toHaveBeenCalled();
  online = true;
  listeners.forEach((wake) => wake());
  await vi.waitFor(async () => expect(await storage.pending.list()).toEqual([]));
  await sync.stop();
  expect(listeners.size).toBe(0);
});

it('surfaces storage failure without claiming local or Server success', async () => {
  const storage = await open();
  const transport = api();
  const sync = coordinator(storage, transport);
  await stage(sync);
  storage.close();
  await expect(sync.syncOnce()).rejects.toMatchObject({ code: 'closed' });
  expect(sync.lastError).toMatchObject({ code: 'closed' });
  expect(transport.push).not.toHaveBeenCalled();
});

it('retains unsupported queued intent as rejected instead of silently dropping it', async () => {
  const storage = await open();
  await storage.pending.stage({
    operationId: crypto.randomUUID(),
    recordType: 'unknown',
    recordId: crypto.randomUUID(),
    expectedRevision: 1,
    command: { type: 'unsupported' },
    localValue: { note: 'Keep' },
  });
  const transport = api();
  await coordinator(storage, transport).syncOnce();
  expect(transport.push).not.toHaveBeenCalled();
  expect(await storage.pending.list()).toMatchObject([
    { state: 'rejected', problem: { code: 'invalid_command' } },
  ]);
  expect(await storage.pending.records()).toHaveLength(1);
});

it('uses foreground timers to retry when the browser remains nominally online', async () => {
  const storage = await open();
  const transport = api();
  transport.push.mockRejectedValueOnce(new TypeError('Server unavailable'));
  const sync = coordinator(storage, transport);
  await stage(sync);
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
  await sync.start();
  expect(transport.push).toHaveBeenCalledTimes(1);
  clock += 999;
  await vi.advanceTimersByTimeAsync(999);
  expect(transport.push).toHaveBeenCalledTimes(1);
  clock += 1;
  await vi.advanceTimersByTimeAsync(1);
  await sync.syncOnce();
  expect(transport.push).toHaveBeenCalledTimes(2);
  expect(await storage.pending.list()).toEqual([]);
  await sync.stop();
});

it('aborts a stalled request after 30 seconds and persists a retryable failure', async () => {
  const storage = await open();
  const transport = api();
  let entered = false;
  transport.push.mockImplementation(
    (_org, _operation, signal) =>
      new Promise((_resolve, reject) => {
        entered = true;
        signal!.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
      }),
  );
  const sync = coordinator(storage, transport);
  await stage(sync);
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
  const flight = sync.syncOnce();
  await vi.waitFor(() => expect(entered).toBe(true));
  clock += 30_000;
  await vi.advanceTimersByTimeAsync(30_000);
  await flight;
  expect(await storage.pending.list()).toMatchObject([{ state: 'failed', attempts: 1 }]);
  expect(await storage.sync.state()).toMatchObject({
    paused: false,
    problem: { code: 'connection_failed' },
  });
});

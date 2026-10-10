// @vitest-environment jsdom
import 'fake-indexeddb/auto';
import { Dexie } from 'dexie';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useSessionStore } from './session';
import { offlineWorkspace } from '../data/repositories/offlineWorkspace';
import { databaseName } from '../data/database/database';
import { openAccountStorage } from '../data/repositories/accountStorage';
import { stageAvailability } from '../data/sync/protocol';

const workspace = {
  key: 'active' as const,
  accountId: 3,
  organizationId: '01920000-0000-7000-8000-000000000002',
  membershipId: '01920000-0000-7000-8000-000000000003',
  label: 'Rangers',
};
const fetchMock = vi.fn<typeof fetch>();
beforeEach(async () => {
  setActivePinia(createPinia());
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
  await offlineWorkspace.put(workspace);
});
afterEach(async () => {
  const session = useSessionStore();
  await session.coordinator?.stop();
  session.storage?.close();
  session.$dispose();
  await Dexie.delete(databaseName(workspace));
  await Dexie.delete('rush:workspace');
  vi.unstubAllGlobals();
});

const validIdentity = {
  user: { id: workspace.accountId, name: 'Casey', email: 'casey@example.test' },
  memberships: [
    {
      id: workspace.membershipId,
      role: 'ranger',
      organization: { id: workspace.organizationId, name: workspace.label },
      profile: null,
    },
  ],
};
function onlineServer() {
  vi.stubGlobal('navigator', {
    onLine: true,
    locks: {
      request: async (_name: string, _options: unknown, work: () => Promise<void>) => work(),
    },
  });
  fetchMock.mockImplementation((url) => {
    if (url === '/api/v1/session' || url === '/login')
      return Promise.resolve(new Response(JSON.stringify(validIdentity)));
    if ((typeof url === 'string' ? url : url instanceof URL ? url.href : url.url).endsWith('/pull'))
      return Promise.resolve(
        new Response(JSON.stringify({ changes: [], checkpoint: 'verified', has_more: false })),
      );
    return Promise.resolve(new Response(null, { status: 204 }));
  });
}
async function stagedWorkspace() {
  const storage = await openAccountStorage(workspace);
  const operation = await stageAvailability(storage, {
    membership_id: workspace.membershipId,
    starts_at: '2026-10-10T09:00:00Z',
    ends_at: '2026-10-10T10:00:00Z',
  });
  storage.close();
  return operation;
}

it('hides confirmed information after role loss while retaining unsent intent in the owner partition', async () => {
  await stagedWorkspace();
  fetchMock.mockRejectedValue(new TypeError('Offline'));
  const session = useSessionStore();
  await session.refresh();
  fetchMock.mockResolvedValue(new Response(JSON.stringify({ ...validIdentity, memberships: [] })));
  await session.refresh();
  expect(session.workspace).toBeNull();
  expect(await offlineWorkspace.get()).toBeUndefined();
  const retained = await openAccountStorage(workspace);
  expect(await retained.pending.records()).toHaveLength(1);
  retained.close();
});

it.each([401, 403, 419])(
  'locks a workspace after sync HTTP %i and preserves intent without further requests',
  async (status) => {
    const operation = await stagedWorkspace();
    onlineServer();
    const base = fetchMock.getMockImplementation()!;
    fetchMock.mockImplementation((url, options) =>
      (typeof url === 'string' ? url : url instanceof URL ? url.href : url.url).endsWith('/push')
        ? Promise.resolve(new Response(null, { status }))
        : base(url, options),
    );
    const session = useSessionStore();
    await session.refresh();
    expect(session.identity).toBeNull();
    expect(session.workspace).toBeNull();
    expect(await offlineWorkspace.get()).toBeUndefined();
    const retained = await openAccountStorage(workspace);
    expect((await retained.pending.records())[0]?.operationId).toBe(operation.operation_id);
    expect((await retained.pending.list())[0]?.state).toBe(status === 403 ? 'rejected' : 'failed');
    const count = fetchMock.mock.calls.length;
    await session.coordinator?.syncOnce();
    expect(fetchMock).toHaveBeenCalledTimes(count);
    retained.close();
  },
);

it('binds push and pull requests to the verified account even if cookies change', async () => {
  onlineServer();
  const session = useSessionStore();
  await session.refresh();
  const pull = fetchMock.mock.calls.find(([url]) =>
    (typeof url === 'string' ? url : url instanceof URL ? url.href : url.url).endsWith('/pull'),
  );
  expect(pull?.[1]?.headers).toMatchObject({ 'X-RUSH-Account': String(workspace.accountId) });
});

it('explicit sign-out preserves pending records but removes the offline locator', async () => {
  await stagedWorkspace();
  fetchMock.mockRejectedValue(new TypeError('Offline'));
  const session = useSessionStore();
  await session.refresh();
  fetchMock.mockResolvedValue(new Response(null, { status: 204 }));
  await session.signOut({ preservePending: true });
  expect(session.workspace).toBeNull();
  expect(await offlineWorkspace.get()).toBeUndefined();
  const retained = await openAccountStorage(workspace);
  expect(await retained.pending.records()).toHaveLength(1);
  expect(await retained.availability.list()).toEqual([]);
  retained.close();
});

it('revokes an open tab when another tab clears the active workspace', async () => {
  fetchMock.mockRejectedValue(new TypeError('Offline'));
  const session = useSessionStore();
  await session.refresh();
  await offlineWorkspace.clear();
  await vi.waitFor(() => expect(session.workspace).toBeNull());
  expect(session.identity).toBeNull();
});

it('does not claim sign-out when CSRF verification fails', async () => {
  const session = useSessionStore();
  session.identity = validIdentity as typeof session.identity;
  fetchMock.mockResolvedValue(new Response(null, { status: 419 }));
  await expect(session.signOut()).rejects.toMatchObject({ status: 419 });
  expect(session.identity).not.toBeNull();
});

it('opens only the cached Ranger workspace on network failure without restoring authentication or pushing', async () => {
  fetchMock.mockRejectedValue(new TypeError('Offline'));
  const session = useSessionStore();
  await session.refresh();
  expect(session.identity).toBeNull();
  expect(session.isManagement).toBe(false);
  expect(session.workspace).toEqual(workspace);
  expect(session.offlineAccess).toBe(true);
  await session.coordinator!.queueAvailability({
    membership_id: workspace.membershipId,
    starts_at: '2026-10-10T09:00:00Z',
    ends_at: '2026-10-10T10:00:00Z',
  });
  expect(await session.storage!.pending.list()).toHaveLength(1);
  expect(fetchMock).toHaveBeenCalledTimes(1);
  await session.refresh();
  expect(await session.storage!.pending.list()).toHaveLength(1);
  expect((await session.storage!.metadata.get())?.key).toBe('cache');
});

it('does not reopen cached information after a known expired session', async () => {
  fetchMock.mockResolvedValue(new Response(null, { status: 401 }));
  const session = useSessionStore();
  await session.refresh();
  expect(session.workspace).toBeNull();
  expect(await offlineWorkspace.get()).toBeUndefined();
});

it('protects unsynchronized intent from sign-out before making any request', async () => {
  const storage = await openAccountStorage(workspace);
  await stageAvailability(storage, {
    membership_id: workspace.membershipId,
    starts_at: '2026-10-10T09:00:00Z',
    ends_at: '2026-10-10T10:00:00Z',
  });
  const session = useSessionStore();
  session.storage = storage;
  await expect(session.signOut()).rejects.toMatchObject({ code: 'pending_work' });
  expect(fetchMock).not.toHaveBeenCalled();
  expect(await storage.pending.list()).toHaveLength(1);
});

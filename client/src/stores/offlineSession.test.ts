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
  await Dexie.delete(databaseName(workspace));
  await Dexie.delete('rush:workspace');
  vi.unstubAllGlobals();
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

// @vitest-environment jsdom
import 'fake-indexeddb/auto';
import { Dexie } from 'dexie';
import { createPinia, setActivePinia } from 'pinia';
import { effectScope, nextTick } from 'vue';
import { afterEach, expect, it } from 'vitest';
import { useSyncStatus } from './useSyncStatus';
import { useSessionStore } from '../stores/session';
import { openAccountStorage, type AccountStorage } from '../data/repositories/accountStorage';
import { databaseName } from '../data/database/database';
import contract from '../../../docs/contracts/sync.example.json';

const scopes: ReturnType<typeof effectScope>[] = [];
const stores: AccountStorage[] = [];
afterEach(async () => {
  scopes.splice(0).forEach((scope) => scope.stop());
  for (const storage of stores.splice(0)) {
    storage.close();
    await Dexie.delete(databaseName(storage.scope));
  }
});
it('reacts to another connection and clears all account status immediately when access is locked', async () => {
  setActivePinia(createPinia());
  const session = useSessionStore();
  const account = { accountId: 714, organizationId: contract.accepted.profile.organization_id };
  const storage = await openAccountStorage(account);
  const other = await openAccountStorage(account);
  stores.push(storage, other);
  session.storage = storage;
  session.workspace = {
    ...account,
    key: 'active',
    membershipId: crypto.randomUUID(),
    label: 'Fixture',
  };
  const scope = effectScope();
  scopes.push(scope);
  const status = scope.run(useSyncStatus)!;
  await expect.poll(() => status.snapshot.value?.state.lastSuccessfulSyncAt).toBe(null);
  await other.pending.stage({
    operationId: crypto.randomUUID(),
    recordType: 'member_profile',
    recordId: contract.operation.record_id,
    expectedRevision: 1,
    command: {},
    localValue: {},
  });
  await expect.poll(() => status.counts.value.pending).toBe(1);
  const command = (await other.pending.list())[0]!;
  await other.sync.mark(command.operationId, 'failed');
  await expect.poll(() => status.counts.value.failed).toBe(1);
  await other.sync.mark(command.operationId, 'conflict');
  await expect.poll(() => status.counts.value.attention).toBe(1);
  session.workspace = null;
  await nextTick();
  expect(status.snapshot.value).toBe(null);
  expect(status.counts.value.pending).toBe(0);
  await other.sync.mark(command.operationId, 'rejected');
  expect(status.snapshot.value).toBe(null);
});

// Test-only entry bundled in memory and injected by Playwright; never shipped in the PWA.
import {
  openAccountStorage,
  type AccountStorage,
} from '../../src/data/repositories/accountStorage';
import { SyncCoordinator } from '../../src/data/sync/coordinator';
import type { AccountScope } from '../../src/data/database/types';
import type { UpdateMemberProfile } from '../../src/data/api/profiles';

let storage: AccountStorage;
let coordinator: SyncCoordinator;
export async function open(scope: AccountScope) {
  storage = await openAccountStorage(scope);
  coordinator = new SyncCoordinator(storage);
}
export function stage(id: string, update: UpdateMemberProfile) {
  return coordinator.queueProfileUpdate(id, update);
}
export function sync() {
  return coordinator.syncOnce();
}
export async function snapshot() {
  return {
    pending: await storage.pending.list(),
    records: await storage.pending.records(),
    profiles: await storage.profiles.list(),
    state: await storage.sync.state(),
  };
}
export async function close() {
  await coordinator.stop();
  storage.close();
}

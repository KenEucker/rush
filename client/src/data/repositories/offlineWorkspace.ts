import { Dexie, liveQuery, type Table } from 'dexie';
import { databaseName } from '../database/database';
import type { AccountScope } from '../database/types';
import { storageError } from '../database/errors';
import { uuid } from '../api/availability';

export interface OfflineWorkspace extends AccountScope {
  key: 'active';
  membershipId: string;
  label: string;
}
// A locator for the last verified Ranger partition, never a session or permission token.
class WorkspaceDirectory extends Dexie {
  workspace!: Table<OfflineWorkspace, string>;
  constructor() {
    super('rush:workspace');
    this.version(1).stores({ workspace: 'key' });
  }
}
async function access<T>(work: (db: WorkspaceDirectory) => Promise<T>): Promise<T> {
  const db = new WorkspaceDirectory();
  try {
    return await work(db);
  } catch (error) {
    throw storageError(error, 'unavailable');
  } finally {
    db.close();
  }
}
export const offlineWorkspace = {
  subscribe(listener: (value: OfflineWorkspace | undefined) => void) {
    const db = new WorkspaceDirectory();
    const subscription = liveQuery(() => db.workspace.get('active')).subscribe({
      next: listener,
      error: () => listener(undefined),
    });
    return () => {
      subscription.unsubscribe();
      db.close();
    };
  },
  get: () =>
    access(async (db) => {
      const value = await db.workspace.get('active');
      if (value) {
        databaseName(value);
        if (!uuid.test(value.membershipId) || typeof value.label !== 'string')
          throw new Error('Invalid offline workspace.');
      }
      return value;
    }),
  put: (value: OfflineWorkspace) =>
    access(async (db) => {
      databaseName(value);
      await db.workspace.put({
        key: 'active',
        accountId: value.accountId,
        organizationId: value.organizationId,
        membershipId: value.membershipId,
        label: value.label,
      });
    }),
  clear: () =>
    access(async (db) => {
      await db.workspace.clear();
    }),
};

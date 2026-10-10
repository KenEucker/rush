import { Dexie, type DexieOptions, type Table } from 'dexie';
import { StorageError, storageError } from './errors';
import type { Unavailability } from '../api/availability';
import type {
  AccountScope,
  CachedProfile,
  CacheMetadata,
  Checkpoint,
  PendingCommand,
  PendingRecord,
  SyncState,
} from './types';

export const SCHEMA_VERSION = 3;
// Keep released schemas immutable. Add numbered versions and transactional upgrade callbacks.
export const schemaV1 = {
  profiles: 'id',
  pendingCommands: 'operationId, state, [recordType+recordId]',
  pendingRecords: 'operationId, [recordType+recordId]',
  checkpoints: 'stream',
  metadata: 'key',
};

export function databaseName(scope: AccountScope): string {
  if (
    !Number.isSafeInteger(scope.accountId) ||
    scope.accountId < 1 ||
    !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(scope.organizationId)
  ) {
    throw new TypeError('A valid account and organization are required for offline storage.');
  }
  return `rush:account:${scope.accountId}:organization:${scope.organizationId.toLowerCase()}`;
}

export const schemaV2 = { ...schemaV1, syncState: 'key' };
export const schemaV3 = { ...schemaV2, unavailabilities: 'id' };

// Internal to the data layer. Components receive repositories, never Dexie tables.
export class AccountDatabase extends Dexie {
  profiles!: Table<CachedProfile, string>;
  unavailabilities!: Table<{ id: string; value: Unavailability; cachedAt: string }, string>;
  pendingCommands!: Table<PendingCommand, string>;
  pendingRecords!: Table<PendingRecord, string>;
  checkpoints!: Table<Checkpoint, string>;
  metadata!: Table<CacheMetadata, string>;
  syncState!: Table<SyncState, string>;
  readonly scope: Readonly<AccountScope>;

  constructor(scope: AccountScope, options?: DexieOptions) {
    super(databaseName(scope), { ...options, autoOpen: false });
    this.scope = Object.freeze({
      accountId: scope.accountId,
      organizationId: scope.organizationId.toLowerCase(),
    });
    this.version(1).stores(schemaV1);
    this.version(2)
      .stores(schemaV2)
      .upgrade(async (tx) => {
        await tx.table('metadata').update('cache', { schemaVersion: 2 });
      });
    this.version(3)
      .stores(schemaV3)
      .upgrade(async (tx) => {
        await tx.table('metadata').update('cache', { schemaVersion: 3 });
      });
    this.on('versionchange', () => {
      // Release upgrades in other tabs; old repositories must not reopen silently.
      this.close();
      return false;
    });
  }

  async initialize(): Promise<void> {
    let wasBlocked = false;
    const onBlocked = () => {
      wasBlocked = true;
      this.close();
    };
    this.on('blocked', onBlocked);
    try {
      await this.open();
      // Dexie 4 can adapt to newer schemas. Fail closed instead of using old code.
      if (this.backendDB().version !== SCHEMA_VERSION * 10) throw new StorageError('schema');
      await this.transaction('rw', this.metadata, async () => {
        const metadata = await this.metadata.get('cache');
        if (metadata) {
          if (
            metadata.accountId !== this.scope.accountId ||
            metadata.organizationId !== this.scope.organizationId ||
            metadata.schemaVersion !== SCHEMA_VERSION
          )
            throw new StorageError('schema');
        } else {
          await this.metadata.add({
            key: 'cache',
            ...this.scope,
            schemaVersion: SCHEMA_VERSION,
            createdAt: new Date().toISOString(),
            lastCachedAt: null,
          });
        }
      });
    } catch (error) {
      this.close();
      throw wasBlocked ? new StorageError('blocked', error) : storageError(error, 'unavailable');
    } finally {
      this.on('blocked').unsubscribe(onBlocked);
    }
  }

  async read<T>(action: () => PromiseLike<T>): Promise<T> {
    try {
      return await action();
    } catch (error) {
      throw storageError(error, 'read_failed');
    }
  }

  async write<T>(action: () => PromiseLike<T>): Promise<T> {
    try {
      return await action();
    } catch (error) {
      throw storageError(error, 'write_failed');
    }
  }
}

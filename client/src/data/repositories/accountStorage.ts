import type { DexieOptions } from 'dexie';
import { parseProfile, type MemberProfile } from '../api/profiles';
import { AccountDatabase } from '../database/database';
import { StorageError } from '../database/errors';
import { syncRepository } from './syncRepository';
import type {
  AccountScope,
  Checkpoint,
  JsonObject,
  PendingCommand,
  PendingRecord,
} from '../database/types';

export class PendingWorkError extends StorageError {
  constructor() {
    super('pending_work');
    this.name = 'PendingWorkError';
  }
}

function repositories(db: AccountDatabase) {
  return {
    scope: db.scope,
    sync: syncRepository(db),
    availability: {
      list: () => db.read(() => db.unavailabilities.toArray()),
    },
    profiles: {
      get: (id: string) => db.read(() => db.profiles.get(id)),
      list: () => db.read(() => db.profiles.toArray()),
      async cache(profiles: readonly MemberProfile[]) {
        // Reuse ingress validation and allowlists; never persist extra session/secret fields.
        const values = profiles.map((profile) =>
          parseProfile(profile, db.scope.organizationId, profile.id),
        );
        await db.write(() =>
          db.transaction('rw', db.profiles, db.metadata, async () => {
            const cachedAt = new Date().toISOString();
            for (const value of values) {
              const existing = await db.profiles.get(value.id);
              if (!existing || value.revision > existing.value.revision) {
                await db.profiles.put({ id: value.id, value, cachedAt });
              }
            }
            await db.metadata.update('cache', { lastCachedAt: cachedAt });
          }),
        );
      },
    },
    pending: {
      list: () => db.read(() => db.pendingCommands.toArray()),
      records: () => db.read(() => db.pendingRecords.toArray()),
      async stage(input: {
        operationId: string;
        recordType: string;
        recordId: string;
        expectedRevision: number | null;
        command: JsonObject;
        localValue: JsonObject | null;
      }) {
        if (
          !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
            input.operationId,
          ) ||
          !input.recordType ||
          !input.recordId ||
          (input.expectedRevision !== null &&
            (!Number.isSafeInteger(input.expectedRevision) || input.expectedRevision < 1))
        )
          throw new TypeError(
            'A unique operation UUID, record and expected revision are required.',
          );
        const command: PendingCommand = {
          operationId: input.operationId.toLowerCase(),
          recordType: input.recordType,
          recordId: input.recordId,
          expectedRevision: input.expectedRevision,
          command: input.command,
          createdAt: new Date().toISOString(),
          state: 'pending',
          attempts: 0,
          problem: null,
        };
        const record: PendingRecord = {
          operationId: input.operationId.toLowerCase(),
          recordType: input.recordType,
          recordId: input.recordId,
          value: input.localValue,
        };
        await db.write(() =>
          db.transaction('rw', db.pendingCommands, db.pendingRecords, async () => {
            await db.pendingCommands.add(command);
            await db.pendingRecords.add(record);
          }),
        );
      },
    },
    checkpoints: {
      get: (stream: string) => db.read(() => db.checkpoints.get(stream)),
      // Tokens are opaque Server values, never derived from a Client timestamp.
      put: (checkpoint: Checkpoint) =>
        db.write(() =>
          db.checkpoints.put({
            stream: checkpoint.stream,
            token: checkpoint.token,
            lastSuccessfulSyncAt: checkpoint.lastSuccessfulSyncAt,
          }),
        ),
    },
    metadata: { get: () => db.read(() => db.metadata.get('cache')) },
    async clearConfirmed() {
      await db.write(() =>
        db.transaction(
          'rw',
          db.profiles,
          db.unavailabilities,
          db.checkpoints,
          db.syncState,
          db.metadata,
          async () => {
            await db.profiles.clear();
            await db.unavailabilities.clear();
            await db.checkpoints.clear();
            await db.metadata.update('cache', { lastCachedAt: null });
          },
        ),
      );
    },
    async clearCache(options: { discardPending?: boolean } = {}) {
      await db.write(() =>
        db.transaction('rw', db.tables, async () => {
          if (
            !options.discardPending &&
            ((await db.pendingCommands.count()) > 0 || (await db.pendingRecords.count()) > 0)
          ) {
            throw new PendingWorkError();
          }
          await db.profiles.clear();
          await db.unavailabilities.clear();
          await db.pendingCommands.clear();
          await db.pendingRecords.clear();
          await db.checkpoints.clear();
          await db.syncState.clear();
          await db.metadata.update('cache', { lastCachedAt: null });
        }),
      );
    },
    close: () => db.close(),
  };
}

export type AccountStorage = ReturnType<typeof repositories>;

// The session/coordinator selects an authorized scope; no global active-account singleton.
// Closing revokes these handles. Reopening is an explicit action for that same scope.
export async function openAccountStorage(
  scope: AccountScope,
  options?: DexieOptions,
): Promise<AccountStorage> {
  const db = new AccountDatabase(scope, options);
  await db.initialize();
  return repositories(db);
}

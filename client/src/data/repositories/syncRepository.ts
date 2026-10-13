import { parsePreference, parsePreferenceCatalog } from '../api/preferences';
import type { AccountDatabase } from '../database/database';
import { parseProfile } from '../api/profiles';
import { parseUnavailability } from '../api/availability';
import type { PendingState, SyncState } from '../database/types';
import {
  parsePullPage,
  parseResult,
  pendingOperation,
  type OperationResult,
  type PullPage,
} from '../sync/protocol';

export const SYNC_STREAM = 'profiles';
const initialState = (): SyncState => ({
  key: 'coordinator',
  failures: 0,
  nextAttemptAt: null,
  paused: false,
  problem: null,
  lastSuccessfulSyncAt: null,
});

// The coordinator holds the account Web Lock across network calls. Transactions
// stay short; no network work occurs inside a Dexie transaction.
export function syncRepository(db: AccountDatabase) {
  return {
    // One coherent account-scoped snapshot, observed by the application shell.
    snapshot: () =>
      db.read(() =>
        db.transaction('r', db.syncState, db.pendingCommands, db.unavailabilities, async () => ({
          state: (await db.syncState.get('coordinator')) ?? initialState(),
          commands: await db.pendingCommands.toArray(),
          assignmentConflicts: (await db.unavailabilities.toArray()).filter(
            ({ value }) => value.assignment_conflicts?.length,
          ).length,
        })),
      ),
    async discardTerminal(operationId: string) {
      await db.write(() =>
        db.transaction('rw', db.pendingCommands, db.pendingRecords, async () => {
          const command = await db.pendingCommands.get(operationId);
          if (!command) return;
          if (!['conflict', 'rejected'].includes(command.state))
            throw new Error('Only a rejected or conflicting change can be discarded.');
          await db.pendingCommands.delete(operationId);
          await db.pendingRecords.delete(operationId);
        }),
      );
    },
    state: () => db.read(async () => (await db.syncState.get('coordinator')) ?? initialState()),
    saveState: (state: SyncState) => db.write(() => db.syncState.put(state)),
    async mark(operationId: string, state: PendingState, problem: SyncState['problem'] = null) {
      await db.write(() =>
        db.transaction('rw', db.pendingCommands, async () => {
          const command = await db.pendingCommands.get(operationId);
          if (!command) return;
          await db.pendingCommands.update(operationId, {
            state,
            problem,
            attempts: command.attempts + (state === 'syncing' ? 1 : 0),
          });
        }),
      );
    },
    async settle(result: OperationResult) {
      await db.write(() =>
        db.transaction('rw', db.pendingCommands, db.pendingRecords, async () => {
          const command = await db.pendingCommands.get(result.operation_id);
          if (!command) return;
          const parsed = parseResult(result, db.scope.organizationId, pendingOperation(command));
          if (parsed.status === 'accepted') {
            if ('unavailability' in parsed || 'preference_profile' in parsed) {
              // Keep the local range visible if the connection drops before pull.
              // Retrying this already accepted operation ID remains idempotent.
              await db.pendingCommands.update(parsed.operation_id, {
                acceptedRevision:
                  'unavailability' in parsed
                    ? parsed.unavailability.revision
                    : parsed.preference_profile.revision,
                state: 'syncing',
                problem: null,
              });
              return;
            }
            // A replay receipt may predate a consumed tombstone or a newer revision.
            // Only the ordered pull stream writes confirmed data.
            await db.pendingCommands.delete(parsed.operation_id);
            await db.pendingRecords.delete(parsed.operation_id);
          } else {
            await db.pendingCommands.update(parsed.operation_id, {
              state: parsed.status,
              problem: {
                code: parsed.error.code,
                message: [parsed.error.message, ...Object.values(parsed.error.errors).flat()].join(
                  ' ',
                ),
              },
            });
          }
        }),
      );
    },
    async applyPage(expected: string | null, input: PullPage, now: string): Promise<boolean> {
      const page = parsePullPage(input, db.scope.organizationId);
      return db.write(() =>
        db.transaction(
          'rw',
          [
            db.profiles,
            db.unavailabilities,
            db.preferences,
            db.preferenceCatalogs,
            db.pendingCommands,
            db.pendingRecords,
            db.checkpoints,
            db.metadata,
          ],
          async () => {
            const current = await db.checkpoints.get(SYNC_STREAM);
            if ((current?.token ?? null) !== expected) return false;
            for (const change of page.changes) {
              if (change.record_type === 'preference_catalog') {
                if (change.value === null) await db.preferenceCatalogs.delete(change.record_id);
                else
                  await db.preferenceCatalogs.put({
                    id: change.record_id,
                    value: parsePreferenceCatalog(
                      change.value,
                      db.scope.organizationId,
                      change.record_id,
                    ),
                    cachedAt: now,
                  });
                continue;
              }
              if (change.record_type === 'preference_profile') {
                const value =
                  change.value === null
                    ? null
                    : parsePreference(change.value, db.scope.organizationId, change.record_id);
                if (value === null) await db.preferences.delete(change.record_id);
                else {
                  const current = await db.preferences.get(change.record_id);
                  if (!current || value.revision >= current.value.revision)
                    await db.preferences.put({ id: change.record_id, value, cachedAt: now });
                }
                const pending = await db.pendingCommands
                  .where('[recordType+recordId]')
                  .equals(['preference_profile', change.record_id])
                  .toArray();
                for (const command of pending) {
                  if (
                    command.acceptedRevision !== undefined &&
                    (value === null || value.revision >= command.acceptedRevision)
                  ) {
                    await db.pendingCommands.delete(command.operationId);
                    await db.pendingRecords.delete(command.operationId);
                  }
                }
                continue;
              }
              if (change.record_type === 'unavailability') {
                if (change.value === null) await db.unavailabilities.delete(change.record_id);
                else {
                  const value = parseUnavailability(
                    change.value,
                    db.scope.organizationId,
                    change.record_id,
                  );
                  const cached = await db.unavailabilities.get(change.record_id);
                  if (!cached || value.revision >= cached.value.revision)
                    await db.unavailabilities.put({ id: change.record_id, value, cachedAt: now });
                }
                const pending = await db.pendingCommands
                  .where('[recordType+recordId]')
                  .equals(['unavailability', change.record_id])
                  .toArray();
                for (const command of pending) {
                  if (
                    command.acceptedRevision !== undefined &&
                    (change.value === null ||
                      ('revision' in change.value ? change.value.revision : 0) >=
                        command.acceptedRevision)
                  ) {
                    await db.pendingCommands.delete(command.operationId);
                    await db.pendingRecords.delete(command.operationId);
                  }
                }
                continue;
              }
              if (change.value === null) {
                await db.profiles.delete(change.record_id);
              } else {
                const cached = await db.profiles.get(change.record_id);
                if (
                  !cached ||
                  parseProfile(change.value, db.scope.organizationId, change.record_id).revision >
                    cached.value.revision
                ) {
                  await db.profiles.put({
                    id: change.record_id,
                    value: parseProfile(change.value, db.scope.organizationId, change.record_id),
                    cachedAt: now,
                  });
                }
              }
            }
            await db.checkpoints.put({
              stream: SYNC_STREAM,
              token: page.checkpoint,
              lastSuccessfulSyncAt: page.has_more ? (current?.lastSuccessfulSyncAt ?? null) : now,
            });
            await db.metadata.update('cache', { lastCachedAt: now });
            return true;
          },
        ),
      );
    },
    async reset(expected: string | null): Promise<boolean> {
      return db.write(() =>
        db.transaction(
          'rw',
          [
            db.profiles,
            db.unavailabilities,
            db.preferences,
            db.preferenceCatalogs,
            db.checkpoints,
            db.syncState,
            db.metadata,
          ],
          async () => {
            const current = await db.checkpoints.get(SYNC_STREAM);
            if ((current?.token ?? null) !== expected) return false;
            await db.profiles.clear();
            await db.unavailabilities.clear();
            await db.preferences.clear();
            await db.preferenceCatalogs.clear();
            await db.checkpoints.delete(SYNC_STREAM);
            await db.metadata.update('cache', { lastCachedAt: null });
            const state = (await db.syncState.get('coordinator')) ?? initialState();
            await db.syncState.put({ ...state, lastSuccessfulSyncAt: null });
            return true;
          },
        ),
      );
    },
  };
}

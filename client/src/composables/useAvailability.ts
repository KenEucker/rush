import { liveQuery } from 'dexie';
import { onScopeDispose, ref, watch } from 'vue';
import { useSessionStore } from '../stores/session';
import type { AvailabilityInput } from '../data/api/availability';
import { localInstant, formatInterval } from '../domain/availability';

export function useAvailability() {
  const session = useSessionStore();
  const entries = ref<
    {
      id: string;
      starts_at: string;
      ends_at: string;
      revision: number | null;
      state: string;
      message: string;
      operationId?: string;
      serverRange?: string;
    }[]
  >([]);
  const pending = ref(0);
  const lastSync = ref<string | null>(null);
  const error = ref('');
  const syncProblem = ref('');
  let unsubscribe: (() => void) | undefined;
  watch(
    () => session.storage,
    (storage) => {
      unsubscribe?.();
      entries.value = [];
      if (!storage) return;
      const subscription = liveQuery(async () => {
        const [records, commands, local, state] = await Promise.all([
          storage.availability.list(),
          storage.pending.list(),
          storage.pending.records(),
          storage.sync.state(),
        ]);
        return { records, commands, local, state };
      }).subscribe({
        next({ records, commands, local, state }) {
          const own = commands.filter((command) => command.recordType === 'unavailability');
          pending.value = commands.length;
          lastSync.value = state.lastSuccessfulSyncAt;
          syncProblem.value = state.problem?.message ?? '';
          entries.value = [
            ...records
              .filter((record) => !own.some((command) => command.recordId === record.id))
              .map(({ value }) => ({ ...value, state: 'synced', message: '' })),
            ...own.map((command) => {
              const current = records.find((record) => record.id === command.recordId)?.value;
              const value = local.find(
                (record) => record.operationId === command.operationId,
              )?.value;
              return {
                id: command.recordId,
                starts_at: typeof value?.starts_at === 'string' ? value.starts_at : '',
                ends_at: typeof value?.ends_at === 'string' ? value.ends_at : '',
                revision: command.expectedRevision,
                state: command.state,
                message: command.problem?.message ?? '',
                operationId: command.operationId,
                ...(current
                  ? { serverRange: formatInterval(current.starts_at, current.ends_at) }
                  : {}),
              };
            }),
          ].sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        },
        error(cause: unknown) {
          error.value = cause instanceof Error ? cause.message : 'Cannot read saved changes.';
        },
      });
      unsubscribe = () => subscription.unsubscribe();
    },
    { immediate: true },
  );
  onScopeDispose(() => unsubscribe?.());
  async function save(
    startDate: string,
    startTime: string,
    endDate: string,
    endTime: string,
    id?: string,
    revision: number | null = null,
  ) {
    if (!session.coordinator || !session.workspace)
      throw new Error('Open your offline workspace first.');
    const input: AvailabilityInput = {
      membership_id: session.workspace.membershipId,
      starts_at: localInstant(startDate, startTime),
      ends_at: localInstant(endDate, endTime),
    };
    await session.coordinator.queueAvailability(input, id, revision);
  }
  return { entries, pending, lastSync, error, syncProblem, save };
}

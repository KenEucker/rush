import { liveQuery } from 'dexie';
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { useSessionStore } from '../stores/session';
import type { AccountStorage } from '../data/repositories/accountStorage';

export function useSyncStatus() {
  const session = useSessionStore();
  const snapshot = shallowRef<Awaited<ReturnType<AccountStorage['sync']['snapshot']>> | null>(null);
  const running = ref(false);
  const error = ref('');
  const readError = ref('');
  let unsubscribe: (() => void) | undefined;
  let unsubscribeRuntime: (() => void) | undefined;
  watch(
    () => [session.storage, session.coordinator, session.workspace] as const,
    ([storage, coordinator, workspace]) => {
      unsubscribe?.();
      unsubscribeRuntime?.();
      snapshot.value = null;
      running.value = false;
      error.value = '';
      readError.value = '';
      if (!storage || !workspace) return;
      const current = () => session.storage === storage && session.workspace === workspace;
      const subscription = liveQuery(() => storage.sync.snapshot()).subscribe({
        next(value) {
          if (current()) snapshot.value = value;
        },
        error(cause: unknown) {
          if (current())
            readError.value =
              cause instanceof Error
                ? cause.message
                : 'Cannot read sync status. Reopen this workspace.';
        },
      });
      unsubscribe = () => subscription.unsubscribe();
      unsubscribeRuntime = coordinator?.subscribeStatus(() => {
        if (!current()) return;
        const status = coordinator.status();
        running.value = status.running;
        if (status.error)
          error.value =
            status.error instanceof Error
              ? status.error.message
              : 'Sync could not start. Reopen this workspace.';
        else error.value = '';
      });
    },
    { immediate: true },
  );
  onScopeDispose(() => {
    unsubscribe?.();
    unsubscribeRuntime?.();
  });
  const counts = computed(() => {
    const commands = snapshot.value?.commands ?? [];
    return {
      pending: commands.length,
      failed: commands.filter((c) => c.state === 'failed').length,
      attention: commands.filter((c) => ['conflict', 'rejected'].includes(c.state)).length,
      assignments: snapshot.value?.assignmentConflicts ?? 0,
    };
  });
  async function retry() {
    error.value = '';
    try {
      await session.coordinator?.retryNow();
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Sync could not start.';
    }
  }
  return {
    snapshot,
    counts,
    running,
    error: computed(() => readError.value || error.value),
    retry,
  };
}

import { liveQuery } from 'dexie';
import { onScopeDispose, ref, watch } from 'vue';
import { useSessionStore } from '../stores/session';
import {
  parsePreferenceInput,
  type PreferenceCatalog,
  type PreferenceInput,
  type PreferenceProfile,
} from '../data/api/preferences';

export interface PreferenceEntry {
  id: string;
  value: PreferenceInput;
  revision: number | null;
  state: string;
  message: string;
  operationId?: string;
}
export function usePreferences() {
  const session = useSessionStore();
  const entries = ref<PreferenceEntry[]>([]);
  const confirmed = ref<PreferenceProfile[]>([]);
  const catalogs = ref<PreferenceCatalog[]>([]);
  const error = ref('');
  let unsubscribe: (() => void) | undefined;
  watch(
    () => session.storage,
    (storage) => {
      unsubscribe?.();
      entries.value = [];
      confirmed.value = [];
      catalogs.value = [];
      error.value = '';
      if (!storage) return;
      const subscription = liveQuery(() => storage.preferences.snapshot()).subscribe({
        next({ profiles, configs, commands, local }) {
          catalogs.value = configs.map((c) => c.value);
          confirmed.value = profiles.map((p) => p.value);
          const own = commands.filter((c) => c.recordType === 'preference_profile');
          entries.value = [
            ...profiles
              .filter((p) => !own.some((c) => c.recordId === p.id))
              .map((p) => ({
                id: p.id,
                value: p.value,
                revision: p.value.revision,
                state: 'synced',
                message: '',
              })),
            ...own.map((c) => ({
              id: c.recordId,
              value: parsePreferenceInput(
                local.find((r) => r.operationId === c.operationId)?.value,
              ),
              revision: c.expectedRevision,
              state: c.state,
              message: c.problem?.message ?? '',
              operationId: c.operationId,
            })),
          ];
        },
        error(cause: unknown) {
          error.value = cause instanceof Error ? cause.message : 'Cannot read saved preferences.';
        },
      });
      unsubscribe = () => subscription.unsubscribe();
    },
    { immediate: true },
  );
  onScopeDispose(() => unsubscribe?.());
  async function save(
    input: PreferenceInput,
    id?: string,
    revision: number | null = null,
    replacesOperationId?: string,
  ) {
    if (!session.coordinator || !session.workspace)
      throw new Error('Open your Ranger workspace first.');
    return session.coordinator.queuePreference(input, id, revision, replacesOperationId);
  }
  return { entries, confirmed, catalogs, error, save };
}

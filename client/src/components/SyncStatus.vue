<template>
  <section
    v-if="session.workspace"
    class="q-pa-md bg-blue-1"
    aria-label="Synchronization"
    data-testid="sync-status"
  >
    <div role="status" aria-live="polite">
      <strong>{{ headline }}</strong>
      <div>{{ online ? 'Network available' : 'Offline with cached information' }}</div>
      <div>
        {{ counts.pending }} pending change(s). Last sync:
        {{
          snapshot?.state.lastSuccessfulSyncAt
            ? new Date(snapshot.state.lastSuccessfulSyncAt).toLocaleString('en-US')
            : 'Not yet synchronized'
        }}.
      </div>
      <div v-if="session.offlineAccess">Cached workspace. Reconnect to verify your session.</div>
      <div v-if="counts.failed">{{ counts.failed }} failed change(s), saved for retry.</div>
      <div v-if="counts.attention">
        {{ counts.attention }} rejected or conflicting change(s) need review.
      </div>
      <div v-if="counts.assignments">
        {{ counts.assignments }} availability report(s) conflict with official assignments. Contact
        Management.
      </div>
      <div v-if="snapshot?.state.nextAttemptAt">
        Automatic retry after
        {{ new Date(snapshot.state.nextAttemptAt).toLocaleTimeString('en-US') }} while this app is
        open.
      </div>
      <div v-if="problem" role="alert">{{ problem }}</div>
    </div>
    <q-btn
      flat
      color="primary"
      label="Sync now"
      :disable="!online || running || session.offlineAccess || !snapshot || snapshot.state.paused"
      @click="retry"
    />
    <q-btn
      v-if="counts.pending || counts.assignments"
      flat
      color="primary"
      label="Review saved changes"
      to="/availability"
    />
    <q-btn
      v-if="snapshot?.state.paused || session.offlineAccess"
      flat
      color="primary"
      label="Sign in again"
      to="/sign-in"
    />
  </section>
</template>
<script setup lang="ts">
import { computed } from 'vue';
import { useSessionStore } from '../stores/session';
import { useSyncStatus } from '../composables/useSyncStatus';
import { useConnectivity } from '../composables/useConnectivity';
const session = useSessionStore();
const { online } = useConnectivity();
const { snapshot, counts, running, error, retry } = useSyncStatus();
const problem = computed(
  () => error.value || session.storageProblem || snapshot.value?.state.problem?.message,
);
const headline = computed(() => {
  if (problem.value || counts.value.failed)
    return 'Synchronization failed — saved changes need attention';
  if (counts.value.attention || counts.value.assignments)
    return 'Conflict or rejection — needs attention';
  if (!online.value) return 'Offline with cached information';
  if (session.offlineAccess || snapshot.value?.state.paused) return 'Session verification needed';
  if (running.value) return 'Synchronization in progress';
  if (counts.value.pending) return 'Changes waiting to synchronize';
  return snapshot.value?.state.lastSuccessfulSyncAt ? 'Synced with Server' : 'Not yet synchronized';
});
</script>

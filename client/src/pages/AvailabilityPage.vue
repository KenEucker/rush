<template>
  <q-page tabindex="-1" class="page-content">
    <h1 class="text-h4" tabindex="-1">Your unavailability</h1>
    <p>Report when you cannot work. Other times remain potentially schedulable.</p>
    <q-banner
      v-if="session.storageProblem || error || readError"
      role="alert"
      class="bg-red-1 text-negative q-mb-md"
    >
      {{ session.storageProblem || error || readError }}
    </q-banner>
    <template v-if="session.workspace && session.storage">
      <p>{{ session.workspace.label }} · Times in {{ timeZone }} (this device)</p>
      <q-banner class="bg-blue-1 q-mb-md" role="status">
        {{
          session.offlineAccess
            ? 'Cached workspace. Reconnect to verify your session.'
            : online
              ? 'Session verified.'
              : 'Offline with cached information.'
        }}
        {{ pending }} pending change(s). Last sync:
        {{ lastSync ? new Date(lastSync).toLocaleString('en-US') : 'Not yet synchronized' }}.
        <div v-if="syncProblem">
          {{ syncProblem }} <router-link to="/sign-in">Sign in again</router-link> if your session
          has expired.
        </div>
      </q-banner>
      <p>
        Saved changes are pending until the Server accepts them. Recording unavailability does not
        change an assignment or mean Management has acknowledged it.
      </p>
      <q-form @submit="submit" class="q-mb-xl">
        <h2 class="text-h6">{{ editingId ? 'Edit time range' : 'Add time range' }}</h2>
        <div class="row q-col-gutter-md q-mb-md">
          <div class="col-12 col-md-6">
            <AvailabilityDateTime v-model:date="startDate" v-model:time="startTime" label="Start" />
          </div>
          <div class="col-12 col-md-6">
            <AvailabilityDateTime v-model:date="endDate" v-model:time="endTime" label="End" />
          </div>
        </div>
        <q-btn label="Save unavailability" color="primary" type="submit" :loading="saving" />
        <q-btn v-if="editingId" label="Cancel edit" flat @click="reset" class="q-ml-sm" />
        <p role="status" class="q-mt-sm">{{ notice }}</p>
      </q-form>
      <h2 class="text-h6">Saved time ranges</h2>
      <p v-if="!entries.length">No saved time ranges on this device.</p>
      <q-list bordered separator v-else>
        <q-item
          v-for="entry in entries"
          :key="entry.operationId ?? entry.id"
          class="q-py-md"
          data-testid="availability-entry"
          :data-record-id="entry.id"
        >
          <q-item-section>
            <q-item-label>{{ formatInterval(entry.starts_at, entry.ends_at) }}</q-item-label>
            <q-item-label class="q-mt-sm">{{ labels[entry.state] ?? entry.state }}</q-item-label>
            <q-item-label v-if="entry.message" caption>{{ entry.message }}</q-item-label>
            <q-item-label
              v-if="entry.serverRange && ['conflict', 'rejected'].includes(entry.state)"
              caption
            >
              Current Server range: {{ entry.serverRange }}
            </q-item-label>
            <q-item-label v-if="['rejected', 'conflict'].includes(entry.state)" caption>
              Your reported intent is preserved. Review the latest range and enter a corrected
              report, or contact Management.
            </q-item-label>
            <div v-if="entry.state === 'synced'" class="q-mt-sm">
              <q-btn flat color="primary" label="Edit time range" @click="edit(entry)" />
            </div>
          </q-item-section>
        </q-item>
      </q-list>
    </template>
    <p v-else>Sign in online as a Ranger to prepare your availability workspace.</p>
  </q-page>
</template>
<script setup lang="ts">
import { ref } from 'vue';
import { useSessionStore } from '../stores/session';
import { useAvailability } from '../composables/useAvailability';
import { useConnectivity } from '../composables/useConnectivity';
import { formatInterval, localParts } from '../domain/availability';
import AvailabilityDateTime from '../components/AvailabilityDateTime.vue';
const session = useSessionStore();
const { online } = useConnectivity();
const { entries, pending, lastSync, error: readError, syncProblem, save } = useAvailability();
const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const startDate = ref(''),
  startTime = ref('09:00 AM'),
  endDate = ref(''),
  endTime = ref('05:00 PM');
const editingId = ref<string>(),
  revision = ref<number | null>(null);
const saving = ref(false),
  error = ref(''),
  notice = ref('');
const labels: Record<string, string> = {
  pending: 'Pending — saved on this device',
  syncing: 'Syncing — waiting for Server confirmation',
  synced: 'Synced — accepted by Server',
  failed: 'Sync failed — saved for retry',
  rejected: 'Rejected — needs attention',
  conflict: 'Conflict — needs attention',
};
function reset() {
  editingId.value = undefined;
  revision.value = null;
  startDate.value = '';
  endDate.value = '';
}
function edit(entry: { id: string; revision: number | null; starts_at: string; ends_at: string }) {
  const start = localParts(entry.starts_at),
    end = localParts(entry.ends_at);
  startDate.value = start.date;
  startTime.value = start.time;
  endDate.value = end.date;
  endTime.value = end.time;
  editingId.value = entry.id;
  revision.value = entry.revision;
  notice.value = '';
}
async function submit() {
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    await save(
      startDate.value,
      startTime.value,
      endDate.value,
      endTime.value,
      editingId.value,
      revision.value,
    );
    notice.value = 'Saved on this device. Pending Server acceptance.';
    reset();
  } catch (cause) {
    error.value =
      cause instanceof Error ? cause.message : 'Could not save. Keep your input and retry.';
  } finally {
    saving.value = false;
  }
}
</script>

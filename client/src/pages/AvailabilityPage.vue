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
            <q-banner
              v-if="entry.assignment_conflicts?.length"
              class="bg-orange-1 q-mt-sm"
              role="alert"
              data-testid="assignment-conflict"
            >
              <strong>Official assignment conflict — Management action needed</strong>
              <p>
                The accepted unavailability overlaps this assignment. The official assignment is
                unchanged and must not be treated as reliable coverage. Contact Management to
                resolve it.
              </p>
              <div v-for="assignment in entry.assignment_conflicts" :key="assignment.id">
                {{ formatInterval(assignment.starts_at, assignment.ends_at) }} · Assignment revision
                {{ assignment.revision }}
              </div>
            </q-banner>
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
            <q-btn
              v-if="['rejected', 'conflict'].includes(entry.state)"
              flat
              color="negative"
              label="Discard this saved change"
              @click="discardId = entry.operationId"
            />
          </q-item-section>
        </q-item>
      </q-list>
    </template>
    <p v-else>Sign in online as a Ranger to prepare your availability workspace.</p>
    <q-dialog :model-value="!!discardId" @update:model-value="discardId = undefined">
      <q-card class="q-pa-md" style="max-width: 420px">
        <h2 class="text-h6">Discard saved intent?</h2>
        <p>
          This removes your rejected or conflicting edit from this device. The current Server range
          remains unchanged. You can then edit that range or submit a new report.
        </p>
        <q-card-actions align="right">
          <q-btn flat label="Keep saved change" v-close-popup />
          <q-btn flat color="negative" label="Confirm discard" @click="discard" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>
<script setup lang="ts">
import { ref } from 'vue';
import { useSessionStore } from '../stores/session';
import { useAvailability } from '../composables/useAvailability';
import { formatInterval, localParts } from '../domain/availability';
import AvailabilityDateTime from '../components/AvailabilityDateTime.vue';
const session = useSessionStore();
const { entries, error: readError, save } = useAvailability();
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
const discardId = ref<string>();
async function discard() {
  try {
    if (discardId.value && session.workspace && session.storage)
      await session.storage.sync.discardTerminal(discardId.value);
    discardId.value = undefined;
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Could not discard the saved change.';
    discardId.value = undefined;
  }
}
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

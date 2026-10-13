<template>
  <q-page class="q-pa-md" style="max-width: 860px; margin: auto">
    <h1 class="text-h4">Scheduling preferences</h1>
    <p>
      Choose shifts you prefer. Unselected shifts remain neutral; use Availability for times you
      cannot work.
    </p>
    <template v-if="session.workspace">
      <q-banner v-if="readError || error" role="alert" class="bg-red-1 q-mb-md">{{
        readError || error
      }}</q-banner>
      <q-banner v-if="notice" role="status" class="bg-blue-1 q-mb-md">{{ notice }}</q-banner>
      <q-form class="q-gutter-md" @submit="submit">
        <q-select
          v-model="scope"
          label="Profile"
          :options="[
            { label: 'Default preferences', value: 'default' },
            { label: 'One week', value: 'weekly' },
          ]"
          emit-value
          map-options
        />
        <template v-if="scope === 'weekly'">
          <q-select
            v-model="seasonId"
            label="Season"
            :options="catalogs.map((c) => ({ label: c.name, value: c.id }))"
            emit-value
            map-options
          />
          <q-input v-model="date" label="Date in the week (YYYY-MM-DD)" mask="####-##-##">
            <template #append
              ><q-btn flat round icon="event" aria-label="Choose preference week"
                ><q-popup-proxy><q-date v-model="date" mask="YYYY-MM-DD" /></q-popup-proxy></q-btn
            ></template>
          </q-input>
          <p v-if="catalog && week">
            {{ dateLabel(week) }} – {{ dateLabel(addDays(week, 6)) }} · {{ catalog.timezone }}
          </p>
          <p>
            Inherited fields follow your current default. Overrides apply only to this week; the
            next week returns to default unless it has its own override.
          </p>
          <q-checkbox v-model="inheritShifts" label="Use default preferred shifts" />
        </template>
        <q-select
          v-model="shifts"
          label="Preferred shifts"
          multiple
          use-chips
          :options="shiftOptions"
          emit-value
          map-options
          :disable="scope === 'weekly' && inheritShifts"
        />
        <p class="text-caption">
          An empty selection means no preferred shifts. Management's shift classifications are
          separate.
        </p>
        <q-select
          v-model="hoursMode"
          label="Desired weekly hours"
          :options="hoursOptions"
          emit-value
          map-options
        />
        <q-input
          v-if="hoursMode === 'target'"
          v-model.number="hours"
          type="number"
          min="0"
          max="168"
          step="any"
          label="Desired hours"
          :rules="[
            (v) =>
              (Number.isFinite(Number(v)) && Number(v) >= 0 && Number(v) <= 168) ||
              'Enter 0–168 hours.',
          ]"
        />
        <q-select
          v-model="daysOff"
          label="Days off preference"
          :options="daysOptions"
          emit-value
          map-options
        />
        <q-banner v-if="effective" class="bg-grey-2" data-testid="effective-preferences">
          <strong>Effective preferences for this week</strong><br />
          Preferred shifts:
          {{ effective.preferred_shift_ids.map(shiftName).join(', ') || 'No preference' }}.<br />
          Desired hours:
          {{
            effective.hours_mode === 'target'
              ? (effective.desired_weekly_minutes ?? 0) / 60
              : effective.hours_mode === 'maximum'
                ? 'As many as permitted'
                : 'No preference'
          }}. Days off: {{ effective.days_off }}.
          {{
            hasPendingDefault ? 'Includes your pending default; awaiting Server acceptance.' : ''
          }}
        </q-banner>
        <q-btn
          type="submit"
          color="primary"
          label="Save preferences"
          :loading="saving"
          :disable="!canSave"
        />
        <q-btn flat label="Load saved profile" @click="loadSelected" />
      </q-form>
      <h2 class="text-h5 q-mt-xl">Saved profiles</h2>
      <p v-if="!entries.length">
        No saved profile yet. All preferences are neutral until you choose them.
      </p>
      <q-card
        v-for="entry in entries"
        :key="entry.operationId ?? entry.id"
        flat
        bordered
        class="q-pa-md q-mb-md"
        data-testid="preference-entry"
        :data-record-id="entry.id"
      >
        <strong>{{
          entry.value.week_starts_on
            ? `Week of ${dateLabel(entry.value.week_starts_on)}`
            : 'Default preferences'
        }}</strong>
        <p>{{ labels[entry.state] }}</p>
        <p>{{ summarize(entry.value) }}</p>
        <p v-if="entry.message" role="alert">{{ entry.message }}</p>
        <template v-if="['conflict', 'rejected'].includes(entry.state)">
          <p>Your saved intent is preserved. Current Server profile: {{ serverSummary(entry) }}</p>
          <q-btn flat color="primary" label="Review saved intent" @click="review(entry)" />
          <q-btn
            flat
            color="negative"
            label="Discard saved change"
            @click="discardId = entry.operationId"
          />
        </template>
        <q-btn
          v-else-if="entry.state === 'synced'"
          flat
          color="primary"
          label="Edit preferences"
          @click="edit(entry)"
        />
      </q-card>
    </template>
    <p v-else>Sign in as a Ranger online to prepare your preferences workspace.</p>
    <q-dialog :model-value="!!discardId" @update:model-value="discardId = undefined">
      <q-card class="q-pa-md"
        ><h2 class="text-h6">Discard saved intent?</h2>
        <p>This removes this device's rejected edit. Your Server profile remains.</p>
        <q-card-actions
          ><q-btn flat label="Keep change" v-close-popup /><q-btn
            flat
            label="Confirm discard"
            color="negative"
            @click="discard" /></q-card-actions
      ></q-card>
    </q-dialog>
  </q-page>
</template>
<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useSessionStore } from '../stores/session';
import { usePreferences, type PreferenceEntry } from '../composables/usePreferences';
import type { PreferenceInput } from '../data/api/preferences';
import { validDate } from '../data/api/preferences';
import {
  addDays,
  applicableShifts,
  dateLabel,
  effectivePreferences,
  weekStart,
} from '../domain/preferences';
const session = useSessionStore();
const { entries, confirmed, catalogs, error: readError, save } = usePreferences();
const scope = ref('default'),
  seasonId = ref(''),
  date = ref('');
const inheritShifts = ref(false),
  shifts = ref<string[]>([]);
const hoursMode = ref<PreferenceInput['hours_mode']>('none'),
  hours = ref<number | null>(null),
  daysOff = ref<PreferenceInput['days_off']>('none');
const editingId = ref<string>(),
  revision = ref<number | null>(null),
  recovering = ref<string>(),
  discardId = ref<string>();
const dirty = ref(false);
watch(
  [shifts, hoursMode, hours, daysOff, inheritShifts],
  () => {
    dirty.value = true;
  },
  { deep: true, flush: 'sync' },
);
const saving = ref(false),
  error = ref(''),
  notice = ref('');
const catalog = computed(() => catalogs.value.find((c) => c.id === seasonId.value));
const week = computed(() =>
  catalog.value && validDate(date.value) ? weekStart(date.value, catalog.value.week_starts_on) : '',
);
const options = computed(() =>
  scope.value === 'weekly'
    ? catalog.value && week.value
      ? applicableShifts(catalog.value, week.value)
      : []
    : catalogs.value.flatMap((c) => c.shifts),
);
const shiftOptions = computed(() =>
  options.value.map((s) => ({
    label: `${s.phase_name} · ${s.name} · ${timeLabel(s.start_time)}`,
    value: s.id,
  })),
);
const hoursOptions = computed(() => [
  ...(scope.value === 'weekly' ? [{ label: 'Use default hours', value: null }] : []),
  { label: 'No preference', value: 'none' },
  { label: 'Set desired hours', value: 'target' },
  { label: 'As many as permitted', value: 'maximum' },
]);
const daysOptions = computed(() => [
  ...(scope.value === 'weekly' ? [{ label: 'Use default days off', value: null }] : []),
  { label: 'No preference', value: 'none' },
  { label: 'Consecutive days off', value: 'consecutive' },
  { label: 'Separated days off', value: 'separated' },
]);
const selected = computed(() => entries.value.find((e) => matches(e.value)));
const canSave = computed(
  () =>
    (scope.value === 'default' ||
      !!(
        catalog.value &&
        week.value &&
        week.value <= catalog.value.ends_on &&
        addDays(week.value, 6) >= catalog.value.starts_on
      )) &&
    (!selected.value || selected.value.state === 'synced' || !!recovering.value),
);
const defaultEntry = computed(() => entries.value.find((e) => e.value.season_id === null));
const hasPendingDefault = computed(
  () => !!defaultEntry.value && defaultEntry.value.state !== 'synced',
);
const effective = computed(() =>
  scope.value === 'weekly' && week.value
    ? effectivePreferences(defaultEntry.value?.value, input())
    : null,
);
const labels: Record<string, string> = {
  pending: 'Pending — saved on this device',
  syncing: 'Syncing — waiting for Server confirmation',
  synced: 'Synced — accepted by Server',
  failed: 'Sync failed — saved for retry',
  rejected: 'Rejected — needs attention',
  conflict: 'Conflict — needs attention',
};
function timeLabel(time: string) {
  const [h, m] = time.split(':');
  return `${Number(h) % 12 || 12}:${m} ${Number(h) < 12 ? 'AM' : 'PM'}`;
}
function shiftName(id: string) {
  return (
    catalogs.value.flatMap((c) => c.shifts).find((s) => s.id === id)?.name ??
    `Unavailable shift (${id})`
  );
}
function matches(v: PreferenceInput) {
  return scope.value === 'default'
    ? v.season_id === null
    : v.season_id === seasonId.value && v.week_starts_on === week.value;
}
function input(): PreferenceInput {
  return {
    membership_id: session.workspace?.membershipId ?? '',
    season_id: scope.value === 'weekly' ? seasonId.value : null,
    week_starts_on: scope.value === 'weekly' ? week.value : null,
    preferred_shift_ids: scope.value === 'weekly' && inheritShifts.value ? null : shifts.value,
    hours_mode: hoursMode.value,
    desired_weekly_minutes:
      hoursMode.value === 'target' && hours.value !== null ? Math.round(hours.value * 60) : null,
    days_off: daysOff.value,
  };
}
function populate(value?: PreferenceInput) {
  inheritShifts.value = scope.value === 'weekly' && (!value || value.preferred_shift_ids === null);
  shifts.value = [...(value?.preferred_shift_ids ?? [])];
  hoursMode.value = value ? value.hours_mode : scope.value === 'weekly' ? null : 'none';
  hours.value = value?.desired_weekly_minutes != null ? value.desired_weekly_minutes / 60 : null;
  daysOff.value = value ? value.days_off : scope.value === 'weekly' ? null : 'none';
}
watch(selected, () => {
  if (!dirty.value && !saving.value && !recovering.value) loadSelected();
});
function loadSelected() {
  const entry = selected.value;
  editingId.value = entry?.id;
  revision.value = entry?.revision ?? null;
  recovering.value = undefined;
  populate(entry?.value);
  dirty.value = false;
}
watch([scope, seasonId, week], loadSelected);
async function edit(entry: PreferenceEntry) {
  scope.value = entry.value.season_id ? 'weekly' : 'default';
  seasonId.value = entry.value.season_id ?? '';
  date.value = entry.value.week_starts_on ?? '';
  await nextTick();
  loadSelected();
  populate(entry.value);
  editingId.value = entry.id;
  revision.value = entry.revision;
}
function currentServer(entry: PreferenceEntry) {
  return confirmed.value.find(
    (p) => p.season_id === entry.value.season_id && p.week_starts_on === entry.value.week_starts_on,
  );
}
async function review(entry: PreferenceEntry) {
  await edit(entry);
  const current = currentServer(entry);
  editingId.value = current?.id ?? entry.id;
  revision.value = current?.revision ?? null;
  recovering.value = entry.operationId;
  notice.value =
    'Review your saved intent against the Server profile below, then save to submit a new revision.';
}
function summarize(v: PreferenceInput) {
  return `Shifts: ${v.preferred_shift_ids === null ? 'Use default' : v.preferred_shift_ids.map(shiftName).join(', ') || 'No preference'}. Hours: ${v.hours_mode === null ? 'Use default' : v.hours_mode === 'target' ? (v.desired_weekly_minutes ?? 0) / 60 : v.hours_mode === 'maximum' ? 'As many as permitted' : 'No preference'}. Days off: ${v.days_off ?? 'Use default'}.`;
}
function serverSummary(entry: PreferenceEntry) {
  const current = currentServer(entry);
  return current ? summarize(current) : 'No accepted profile for this scope.';
}
async function submit() {
  saving.value = true;
  error.value = '';
  try {
    await save(input(), editingId.value, revision.value, recovering.value);
    recovering.value = undefined;
    dirty.value = false;
    notice.value = 'Saved on this device. See each profile’s synchronization status below.';
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Could not save preferences.';
  } finally {
    saving.value = false;
  }
}
async function discard() {
  try {
    if (discardId.value) await session.storage?.sync.discardTerminal(discardId.value);
    discardId.value = undefined;
    await nextTick();
    loadSelected();
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Could not discard change.';
  }
}
</script>

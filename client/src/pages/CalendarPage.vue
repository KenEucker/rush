<template>
  <q-page tabindex="-1" class="page-content">
    <h1 class="text-h4" tabindex="-1">Calendar preview</h1>
    <q-banner role="note" class="bg-blue-1 q-mb-lg">
      Preview only. No assignments are loaded. These controls do not save or change a schedule.
    </q-banner>
    <div class="row items-center q-gutter-sm q-mb-md" aria-label="Calendar navigation">
      <q-btn
        outline
        color="primary"
        icon="chevron_left"
        label="Previous day"
        no-caps
        @click="selectedDate = shiftDate(selectedDate, -1)"
      />
      <q-btn outline color="primary" label="Today" no-caps @click="selectedDate = today()" />
      <q-btn
        outline
        color="primary"
        icon-right="chevron_right"
        label="Next day"
        no-caps
        @click="selectedDate = shiftDate(selectedDate, 1)"
      />
    </div>
    <h2 class="text-h6" aria-live="polite">{{ dateLabel(selectedDate) }}</h2>
    <q-calendar-agenda
      v-model="selectedDate"
      view="day"
      locale="en-US"
      bordered
      :day-min-height="120"
      no-active-date
      no-default-header-btn
    >
      <template #day>
        <p class="q-pa-md">Schedule information is not connected yet.</p>
      </template>
    </q-calendar-agenda>
    <q-expansion-item
      label="Try date and time controls"
      icon="edit_calendar"
      class="q-mt-lg bg-white"
      header-class="text-primary"
    >
      <div class="q-pa-md">
        <p>Choose a preview date and time range. Values reset when you leave this page.</p>
        <p class="text-caption">
          Calendar dates only; no season time zone or paid hours are calculated.
        </p>
        <div class="preview-controls">
          <section aria-labelledby="preview-date-heading">
            <h3 id="preview-date-heading" class="text-subtitle1">Preview date</h3>
            <q-date
              v-model="selectedDate"
              mask="YYYY-MM-DD"
              no-unset
              minimal
              :default-year-month="selectedDate.slice(0, 7).replace('-', '/')"
            />
          </section>
          <section aria-labelledby="preview-start-heading">
            <h3 id="preview-start-heading" class="text-subtitle1">Start time</h3>
            <q-time
              v-model="startTime"
              mask="HH:mm"
              :format24h="false"
              aria-label="Preview start time"
            />
          </section>
          <section aria-labelledby="preview-end-heading">
            <h3 id="preview-end-heading" class="text-subtitle1">End time</h3>
            <q-time
              v-model="endTime"
              mask="HH:mm"
              :format24h="false"
              aria-label="Preview end time"
            />
          </section>
        </div>
        <q-checkbox v-model="endsNextDay" label="Ends the next day (overnight)" />
        <p v-if="!endsNextDay && endTime <= startTime" role="alert" class="text-negative">
          End time must be after start time, or select “Ends the next day”.
        </p>
        <p v-else role="status" class="text-weight-medium" data-testid="preview-range">
          {{ rangeLabel(selectedDate, startTime, endTime, endsNextDay) }}
        </p>
      </div>
    </q-expansion-item>
  </q-page>
</template>
<script setup lang="ts">
import { ref } from 'vue';
import { date } from 'quasar';
import { QCalendarAgenda } from '@quasar/quasar-ui-qcalendar';
import '@quasar/quasar-ui-qcalendar/index.css';
import { dateLabel, rangeLabel, shiftDate } from '../ui/calendarPreview';
const today = () => date.formatDate(new Date(), 'YYYY-MM-DD');
const selectedDate = ref(today());
const startTime = ref('09:00');
const endTime = ref('17:00');
const endsNextDay = ref(false);
</script>
<style scoped>
.preview-controls {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
  gap: 16px;
}
.preview-controls .q-date,
.preview-controls .q-time {
  width: 100%;
  min-width: 0;
}
@media (max-width: 359px) {
  .preview-controls {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>

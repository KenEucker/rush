<template>
  <fieldset class="q-pa-md rounded-borders">
    <legend class="text-subtitle1">{{ label }}</legend>
    <q-input v-model="date" :label="label + ' date'" hint="YYYY-MM-DD" outlined class="q-mb-md">
      <template #append>
        <q-btn flat round icon="event" :aria-label="'Choose ' + label.toLowerCase() + ' date'">
          <q-popup-proxy cover transition-show="scale" transition-hide="scale">
            <q-date v-model="date" mask="YYYY-MM-DD">
              <div class="row justify-end">
                <q-btn v-close-popup flat label="Done" color="primary" />
              </div>
            </q-date>
          </q-popup-proxy>
        </q-btn>
      </template>
    </q-input>
    <q-input v-model="time" :label="label + ' time'" hint="For example, 09:00 AM" outlined>
      <template #append>
        <q-btn flat round icon="schedule" :aria-label="'Choose ' + label.toLowerCase() + ' time'">
          <q-popup-proxy cover transition-show="scale" transition-hide="scale">
            <q-time v-model="time" mask="hh:mm A" :format24h="false">
              <div class="row justify-end">
                <q-btn v-close-popup flat label="Done" color="primary" />
              </div>
            </q-time>
          </q-popup-proxy>
        </q-btn>
      </template>
    </q-input>
  </fieldset>
</template>
<script setup lang="ts">
defineProps<{ label: string }>();
const date = defineModel<string>('date', { required: true });
const time = defineModel<string>('time', { required: true });
</script>

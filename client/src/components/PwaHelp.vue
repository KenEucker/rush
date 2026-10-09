<template>
  <div class="q-px-md q-py-sm">
    <q-banner v-if="state.updateAvailable" role="status" class="bg-blue-1 q-mb-sm">
      An app update is ready. Finish your work, then close all RUSH tabs and windows and reopen RUSH
      to use it.
    </q-banner>
    <q-banner v-if="state.shell === 'unavailable'" role="alert" class="bg-orange-1 q-mb-sm">
      Offline setup is unavailable. Keep a connection while using RUSH, and reload when connected to
      try again.
    </q-banner>
    <q-expansion-item icon="install_mobile" label="Install RUSH" :caption="caption">
      <div class="q-pa-md">
        <p>Use your browser menu to install RUSH or add it to your home screen.</p>
        <p>On iPhone or iPad, open RUSH in Safari, choose Share, then Add to Home Screen.</p>
        <p class="q-mb-none">
          Open RUSH online and wait for “App ready for offline startup” before going offline.
          Signing in and administration require a connection. Installing the app does not
          synchronize account data.
        </p>
      </div>
    </q-expansion-item>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { pwaLifecycle } from '../pwa/lifecycle';

const state = pwaLifecycle.state;
const caption = computed(
  () =>
    ({
      preparing: 'Preparing app for offline startup…',
      ready: 'App ready for offline startup',
      unavailable: 'A connection is needed',
    })[state.shell],
);
</script>

<template>
  <q-layout view="hHh Lpr fFf">
    <a class="skip-link" href="#main-content" @click.prevent="focusContent">Skip to main content</a>
    <q-header class="bg-primary text-white">
      <q-toolbar>
        <q-btn
          v-if="showNavigation"
          flat
          round
          icon="menu"
          aria-label="Toggle navigation"
          aria-controls="primary-navigation"
          :aria-expanded="drawerOpen"
          @click="drawerOpen = !drawerOpen"
        />
        <q-toolbar-title
          >RUSH
          <span class="text-caption q-ml-sm">Ranger scheduling &amp; hours</span></q-toolbar-title
        >
      </q-toolbar>
    </q-header>

    <q-drawer
      v-if="showNavigation"
      v-model="drawerOpen"
      show-if-above
      bordered
      :width="256"
      :breakpoint="900"
    >
      <nav id="primary-navigation" aria-label="Primary navigation" class="q-py-md">
        <p class="text-overline q-px-md q-mb-sm">
          {{ session.isManagement ? 'Management' : 'Ranger' }} workspace
        </p>
        <q-list>
          <q-item
            v-for="item in navigation"
            :key="item.to"
            clickable
            :to="item.to"
            :aria-current="route.path === item.to ? 'page' : undefined"
            exact
            active-class="navigation-active"
            @click="closeMobileNavigation"
          >
            <q-item-section avatar><q-icon :name="item.icon" /></q-item-section>
            <q-item-section>{{ item.label }}</q-item-section>
          </q-item>
          <q-item v-if="session.isManagement" clickable tag="a" href="/admin" :disable="!online">
            <q-item-section avatar><q-icon name="admin_panel_settings" /></q-item-section>
            <q-item-section>
              <q-item-label>Administration</q-item-label>
              <q-item-label caption>Management · online only</q-item-label>
            </q-item-section>
          </q-item>
        </q-list>
        <q-separator class="q-my-md" />
        <p class="q-px-md text-caption">More tools will appear as they become available.</p>
        <q-list aria-label="Upcoming tools">
          <q-item
            v-for="label in upcomingTools"
            :key="label"
            aria-disabled="true"
            class="text-grey-8"
          >
            <q-item-section>
              <q-item-label>{{ label }}</q-item-label>
              <q-item-label caption>Not available yet</q-item-label>
            </q-item-section>
          </q-item>
        </q-list>
      </nav>
    </q-drawer>

    <q-page-container>
      <div id="main-content" ref="mainContent" tabindex="-1">
        <SyncStatus v-if="session.workspace" />
        <div v-else class="connection-status q-px-md q-py-sm" role="status">
          <q-icon :name="online ? 'wifi' : 'wifi_off'" class="q-mr-xs" />
          {{ online ? 'Network available' : 'Offline' }} ·
          {{
            online
              ? 'Saved availability synchronizes with the Server.'
              : 'Cached availability is available; changes wait for Server acceptance.'
          }}
        </div>
        <PwaHelp v-if="isPwa" />
        <q-page tabindex="-1" v-if="loading" class="q-pa-lg" aria-busy="true">
          <q-spinner aria-label="Checking your session" color="primary" size="2em" />
          <p role="status">Checking your session…</p>
        </q-page>
        <q-page tabindex="-1" v-else-if="error" class="q-pa-lg">
          <h1 class="text-h4" tabindex="-1">
            {{ online ? 'Connection needed' : 'RUSH is offline' }}
          </h1>
          <p v-if="!online">Reconnect to verify your account and continue.</p>
          <q-banner role="alert" class="bg-red-1 text-negative q-mb-md">{{ error }}</q-banner>
          <q-btn label="Retry connection" color="primary" @click="loadSession" />
        </q-page>
        <q-page
          v-else-if="
            session.storageProblem &&
            !session.identity &&
            !session.workspace &&
            route.meta.requiresSession
          "
          tabindex="-1"
          class="q-pa-lg"
        >
          <h1 class="text-h4" tabindex="-1">Session needs attention</h1>
          <q-banner role="alert" class="bg-orange-1 q-mb-md">{{ session.storageProblem }}</q-banner>
          <q-btn label="Sign in again" color="primary" to="/sign-in" />
        </q-page>
        <router-view v-else />
      </div>
    </q-page-container>
  </q-layout>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useQuasar } from 'quasar';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '../data/api/session';
import { useSessionStore } from '../stores/session';
import { useConnectivity } from '../composables/useConnectivity';
import PwaHelp from '../components/PwaHelp.vue';
import SyncStatus from '../components/SyncStatus.vue';

const isPwa = import.meta.env.QUASAR_MODE === 'pwa';

const session = useSessionStore();
const route = useRoute();
const router = useRouter();
const quasar = useQuasar();
const { online } = useConnectivity();
const drawerOpen = ref(false);
const mainContent = ref<HTMLElement>();
const loading = ref(false);
const error = ref('');
let request = 0;
const showNavigation = computed(
  () =>
    (!!session.identity || !!session.workspace) &&
    !loading.value &&
    !error.value &&
    route.meta.requiresSession,
);
const navigation = [
  { to: '/', label: 'Overview', icon: 'home' },
  { to: '/calendar', label: 'Calendar preview', icon: 'calendar_month' },
  { to: '/availability', label: 'Availability', icon: 'event_busy' },
  { to: '/account', label: 'Your account', icon: 'person' },
];
const upcomingTools = computed(() =>
  session.isManagement
    ? ['Planning', 'Schedule changes', 'Timesheet review', 'Reports']
    : ['Shift activity', 'Timesheets', 'Notifications'],
);

function closeMobileNavigation() {
  if (quasar.screen.width <= 900) drawerOpen.value = false;
}

function focusContent() {
  mainContent.value?.querySelector<HTMLElement>('main')?.focus();
}

async function loadSession() {
  const currentRequest = ++request;
  error.value = '';
  loading.value = !!route.meta.requiresSession && !session.workspace;
  document.title = (typeof route.meta.title === 'string' ? route.meta.title : 'RUSH') + ' · RUSH';
  if (route.meta.requiresSession) {
    try {
      await session.refresh();
      if (currentRequest !== request) return;
      if (!session.identity && !(session.workspace && route.path === '/availability')) {
        if (session.workspace) {
          await router.replace('/availability');
          return;
        }
        await router.replace('/sign-in');
        return;
      }
    } catch (cause) {
      if (currentRequest !== request) return;
      error.value =
        cause instanceof ApiError
          ? cause.message
          : 'Unable to connect. Check your connection and retry.';
    }
  }
  if (currentRequest === request) {
    loading.value = false;
    await nextTick();
    mainContent.value?.querySelector<HTMLElement>('h1')?.focus();
  }
}

watch(
  () => route.fullPath,
  () => {
    closeMobileNavigation();
    void loadSession();
  },
  { immediate: true },
);
watch(online, (connected) => {
  if (connected && route.meta.requiresSession) void loadSession();
});
</script>

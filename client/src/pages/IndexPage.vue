<template>
  <q-page class="q-pa-lg">
    <section class="q-mx-auto" style="max-width: 720px">
      <h1 class="text-h4">Your RUSH account</h1>
      <q-banner v-if="error" role="alert" class="bg-red-1 text-negative q-mb-md">{{
        error
      }}</q-banner>
      <q-spinner v-if="loading" aria-label="Checking your session" color="primary" size="2em" />
      <template v-else-if="session.identity">
        <p class="text-h6 q-mb-xs">{{ session.identity.user.name }}</p>
        <p>{{ session.identity.user.email }}</p>
        <q-list bordered separator class="rounded-borders q-mb-lg">
          <q-item v-for="membership in session.identity.memberships" :key="membership.id">
            <q-item-section>
              <q-item-label>{{ membership.organization.name }}</q-item-label>
              <q-item-label caption>{{
                membership.role === 'management' ? 'Management' : 'Ranger'
              }}</q-item-label>
            </q-item-section>
          </q-item>
        </q-list>
        <p v-if="!session.identity.memberships.length">
          You have no active organization membership. Contact Management.
        </p>
        <div class="q-gutter-sm">
          <q-btn
            v-if="
              session.identity.memberships.some((membership) => membership.role === 'management')
            "
            href="/admin"
            label="Open administration"
            color="primary"
          />
          <q-btn label="Sign out" outline color="primary" :loading="signingOut" @click="signOut" />
        </div>
        <p class="text-caption q-mt-lg">An internet connection is required to sign out.</p>
      </template>
      <q-btn v-else-if="error" label="Retry connection" color="primary" @click="load" />
    </section>
  </q-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError } from '../data/api/session';
import { useSessionStore } from '../stores/session';

const session = useSessionStore();
const router = useRouter();
const loading = ref(true);
const signingOut = ref(false);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    await session.refresh();
    if (!session.identity) await router.replace('/sign-in');
  } catch (cause) {
    error.value =
      cause instanceof ApiError
        ? cause.message
        : 'Unable to connect. Check your connection and retry.';
  } finally {
    loading.value = false;
  }
}

async function signOut() {
  signingOut.value = true;
  error.value = '';
  try {
    await session.signOut();
    await router.replace('/sign-in');
  } catch (cause) {
    error.value =
      cause instanceof ApiError
        ? cause.message
        : 'Sign-out was not confirmed. Reconnect and try again.';
  } finally {
    signingOut.value = false;
  }
}

onMounted(load);
</script>

<template>
  <q-page tabindex="-1" class="page-content">
    <h1 class="text-h4" tabindex="-1">Your RUSH account</h1>
    <q-banner v-if="error" role="alert" class="bg-red-1 text-negative q-mb-md">{{
      error
    }}</q-banner>
    <template v-if="session.identity">
      <p class="text-h6 q-mb-xs">{{ session.identity.user.name }}</p>
      <p class="account-email">{{ session.identity.user.email }}</p>
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
      <q-btn label="Sign out" outline color="primary" :loading="signingOut" @click="signOut()" />
      <q-banner v-if="pendingWarning" class="bg-orange-1 q-mt-md" role="alert">
        Unsent or unresolved work is saved on this device for your account. Signing out will hide it
        until you sign in to this account again. Do not clear browser storage.
        <q-btn
          label="Keep saved work and sign out"
          color="primary"
          @click="signOut(true)"
          :loading="signingOut"
        />
      </q-banner>
      <p class="text-caption q-mt-lg">An internet connection is required to sign out.</p>
    </template>
  </q-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError } from '../data/api/session';
import { PendingWorkError } from '../data/repositories/accountStorage';
import { useSessionStore } from '../stores/session';

const session = useSessionStore();
const router = useRouter();
const signingOut = ref(false);
const error = ref('');
const pendingWarning = ref(false);
async function signOut(preservePending = false) {
  signingOut.value = true;
  error.value = '';
  try {
    await session.signOut({ preservePending });
    await router.replace('/sign-in');
  } catch (cause) {
    pendingWarning.value = cause instanceof PendingWorkError;
    error.value =
      cause instanceof PendingWorkError
        ? 'You have saved changes waiting for the Server. You can keep them safely for your next sign-in.'
        : cause instanceof ApiError
          ? cause.message
          : 'Sign-out was not confirmed. Reconnect and try again.';
  } finally {
    signingOut.value = false;
  }
}
</script>

<template>
  <q-page tabindex="-1" class="q-pa-lg flex flex-center">
    <section style="width: 100%; max-width: 420px" aria-labelledby="sign-in-title">
      <p class="text-overline text-primary">Ranger Unified Scheduling &amp; Hours</p>
      <h1 id="sign-in-title" class="text-h4" tabindex="-1">Sign in to RUSH</h1>
      <p>Use the account provided by your organization.</p>
      <q-banner v-if="error" role="alert" class="bg-red-1 text-negative q-mb-md">{{
        error
      }}</q-banner>
      <q-form class="q-gutter-md" @submit="submit">
        <q-input
          v-model="email"
          label="Email"
          type="email"
          autocomplete="username"
          outlined
          required
          :disable="busy"
        />
        <q-input
          v-model="password"
          label="Password"
          type="password"
          autocomplete="current-password"
          outlined
          required
          :disable="busy"
        />
        <q-btn type="submit" label="Sign in" color="primary" :loading="busy" />
      </q-form>
      <p class="text-caption q-mt-lg">An internet connection is required to sign in.</p>
    </section>
  </q-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError } from '../data/api/session';
import { useSessionStore } from '../stores/session';

const session = useSessionStore();
const router = useRouter();
const email = ref('');
const password = ref('');
const busy = ref(false);
const error = ref('');

async function submit() {
  busy.value = true;
  error.value = '';
  try {
    await session.signIn(email.value, password.value);
    await router.replace('/');
  } catch (cause) {
    error.value =
      cause instanceof ApiError
        ? cause.message
        : 'Unable to connect. Check your connection and try again.';
  } finally {
    password.value = '';
    busy.value = false;
  }
}
</script>

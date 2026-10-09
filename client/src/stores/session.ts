import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ApiError, login, logout, sessionRequest, type SessionIdentity } from '../data/api/session';

export const useSessionStore = defineStore('session', () => {
  const identity = ref<SessionIdentity | null>(null);
  const checked = ref(false);
  const isManagement = computed(
    () =>
      identity.value?.memberships.some((membership) => membership.role === 'management') ?? false,
  );

  async function refresh() {
    try {
      identity.value = await sessionRequest<SessionIdentity>('/api/v1/session');
      checked.value = true;
    } catch (error) {
      identity.value = null;
      checked.value = true;
      if (!(error instanceof ApiError && error.status === 401)) throw error;
    }
  }

  async function signIn(email: string, password: string) {
    identity.value = null;
    identity.value = await login(email, password);
    checked.value = true;
  }

  async function signOut() {
    // Keep the session visible if the Server cannot confirm sign-out.
    // RUSH-008/013 must protect pending Dexie commands before invoking this action.
    await logout();
    identity.value = null;
    checked.value = true;
  }

  return { identity, checked, isManagement, refresh, signIn, signOut };
});

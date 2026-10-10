import { defineStore } from 'pinia';
import { computed, ref, shallowRef } from 'vue';
import { ApiError, login, logout, sessionRequest, type SessionIdentity } from '../data/api/session';
import {
  openAccountStorage,
  type AccountStorage,
  PendingWorkError,
} from '../data/repositories/accountStorage';
import { offlineWorkspace, type OfflineWorkspace } from '../data/repositories/offlineWorkspace';
import { SyncCoordinator } from '../data/sync/coordinator';

export const useSessionStore = defineStore('session', () => {
  const identity = ref<SessionIdentity | null>(null);
  const checked = ref(false);
  const workspace = shallowRef<OfflineWorkspace | null>(null);
  const storage = shallowRef<AccountStorage | null>(null);
  const coordinator = shallowRef<SyncCoordinator | null>(null);
  const storageProblem = ref('');
  const offlineAccess = ref(false);

  async function closeWorkspace() {
    await coordinator.value?.stop();
    coordinator.value = null;
    storage.value?.close();
    storage.value = null;
    workspace.value = null;
    offlineAccess.value = false;
  }
  async function openWorkspace(value: OfflineWorkspace, verified: boolean) {
    if (
      workspace.value?.accountId !== value.accountId ||
      workspace.value?.organizationId !== value.organizationId
    ) {
      await closeWorkspace();
      storage.value = await openAccountStorage(value);
      coordinator.value = new SyncCoordinator(storage.value);
      workspace.value = value;
    }
    offlineAccess.value = !verified;
    if (verified) {
      await offlineWorkspace.put(value);
      await coordinator.value?.resumeAfterAuthentication();
    }
  }
  async function verifiedWorkspace() {
    storageProblem.value = '';
    try {
      const membership = identity.value?.memberships.find((item) => item.role === 'ranger');
      if (!identity.value || !membership) {
        await closeWorkspace();
        await offlineWorkspace.clear();
        return;
      }
      await openWorkspace(
        {
          key: 'active',
          accountId: identity.value.user.id,
          organizationId: membership.organization.id,
          membershipId: membership.id,
          label: membership.organization.name,
        },
        true,
      );
    } catch (error) {
      storageProblem.value =
        error instanceof Error ? error.message : 'Offline storage is unavailable.';
    }
  }
  const isManagement = computed(
    () =>
      identity.value?.memberships.some((membership) => membership.role === 'management') ?? false,
  );

  async function refresh() {
    try {
      identity.value = await sessionRequest<SessionIdentity>('/api/v1/session');
      checked.value = true;
      await verifiedWorkspace();
    } catch (error) {
      identity.value = null;
      checked.value = true;
      await closeWorkspace();
      if (error instanceof TypeError) {
        const cached = await offlineWorkspace.get();
        if (cached) {
          await openWorkspace(cached, false);
          return;
        }
      } else {
        await offlineWorkspace.clear().catch(() => {});
      }
      if (!(error instanceof ApiError && error.status === 401)) throw error;
    }
  }

  async function signIn(email: string, password: string) {
    identity.value = null;
    await closeWorkspace();
    await offlineWorkspace.clear().catch(() => {});
    identity.value = await login(email, password);
    checked.value = true;
    await verifiedWorkspace();
  }

  async function signOut() {
    if (storage.value && (await storage.value.pending.list()).length) throw new PendingWorkError();
    await logout();
    await closeWorkspace();
    await offlineWorkspace.clear().catch(() => {});
    identity.value = null;
    checked.value = true;
  }

  return {
    identity,
    checked,
    isManagement,
    refresh,
    signIn,
    signOut,
    workspace,
    storage,
    coordinator,
    storageProblem,
    offlineAccess,
  };
});

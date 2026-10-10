import { defineStore } from 'pinia';
import { computed, ref, shallowRef, onScopeDispose } from 'vue';
import { ApiError, login, logout, sessionRequest, type SessionIdentity } from '../data/api/session';
import {
  openAccountStorage,
  type AccountStorage,
  PendingWorkError,
} from '../data/repositories/accountStorage';
import { offlineWorkspace, type OfflineWorkspace } from '../data/repositories/offlineWorkspace';
import { pullChanges, pushOperation } from '../data/api/sync';
import { SyncCoordinator } from '../data/sync/coordinator';

export const useSessionStore = defineStore('session', () => {
  const identity = ref<SessionIdentity | null>(null);
  const checked = ref(false);
  const workspace = shallowRef<OfflineWorkspace | null>(null);
  const storage = shallowRef<AccountStorage | null>(null);
  const coordinator = shallowRef<SyncCoordinator | null>(null);
  const storageProblem = ref('');
  const offlineAccess = ref(false);

  let lifecycle = Promise.resolve();
  function serialize<T>(action: () => Promise<T>): Promise<T> {
    const task = lifecycle.then(action, action);
    lifecycle = task.then(
      () => {},
      () => {},
    );
    return task;
  }
  let unsubscribeDirectory: (() => void) | undefined;
  onScopeDispose(() => {
    unsubscribeDirectory?.();
    void coordinator.value?.stop();
    storage.value?.close();
  });
  async function closeWorkspace() {
    unsubscribeDirectory?.();
    unsubscribeDirectory = undefined;
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
      workspace.value?.organizationId !== value.organizationId ||
      workspace.value?.membershipId !== value.membershipId
    ) {
      await closeWorkspace();
      storage.value = await openAccountStorage(value);
      const accountId = value.accountId;
      coordinator.value = new SyncCoordinator(
        storage.value,
        undefined,
        {
          push: (org, operation, signal) =>
            pushOperation(org, operation, signal, accountId, value.membershipId),
          pull: (org, checkpoint, limit, signal) =>
            pullChanges(org, checkpoint, limit, signal, accountId, value.membershipId),
        },
        async (error) => {
          storageProblem.value =
            error.message +
            ' Your saved intent is retained for this account. Sign in again or contact Management.';
          identity.value = null;
          workspace.value = null;
          offlineAccess.value = false;
          unsubscribeDirectory?.();
          unsubscribeDirectory = undefined;
          await storage.value?.clearConfirmed();
          await offlineWorkspace.clear();
        },
      );
      workspace.value = value;
    }
    offlineAccess.value = !verified;
    if (verified) {
      await offlineWorkspace.put(value);
      await coordinator.value?.resumeAfterAuthentication();
    }
    if (!workspace.value) return;
    unsubscribeDirectory?.();
    const watchedStorage = storage.value;
    unsubscribeDirectory = offlineWorkspace.subscribe((active) => {
      if (
        active?.accountId !== value.accountId ||
        active?.organizationId !== value.organizationId ||
        active?.membershipId !== value.membershipId
      ) {
        identity.value = null;
        workspace.value = null;
        storageProblem.value =
          'The account changed in another tab. Sign in again to reopen your saved work.';
        void serialize(async () => {
          if (storage.value === watchedStorage) await closeWorkspace();
        });
      }
    });
  }
  async function verifiedWorkspace() {
    storageProblem.value = '';
    try {
      const membership = identity.value?.memberships.find((item) => item.role === 'ranger');
      if (!identity.value || !membership) {
        await coordinator.value?.stop();
        await storage.value?.clearConfirmed();
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
      await coordinator.value?.stop();
      if (!(error instanceof TypeError)) await storage.value?.clearConfirmed();
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
    storageProblem.value = '';
    identity.value = null;
    await closeWorkspace();
    await offlineWorkspace.clear().catch(() => {});
    identity.value = await login(email, password);
    checked.value = true;
    await verifiedWorkspace();
  }

  async function signOut(options: { preservePending?: boolean } = {}) {
    if (!options.preservePending && storage.value && (await storage.value.pending.list()).length)
      throw new PendingWorkError();
    await coordinator.value?.stop();
    try {
      await logout();
    } catch (error) {
      if (!(error instanceof ApiError && error.status === 401)) {
        if (identity.value) await coordinator.value?.start();
        throw error;
      }
    }
    await storage.value?.clearConfirmed();
    await closeWorkspace();
    await offlineWorkspace.clear().catch(() => {});
    identity.value = null;
    checked.value = true;
  }

  return {
    identity,
    checked,
    isManagement,
    refresh: () => serialize(refresh),
    signIn: (email: string, password: string) => serialize(() => signIn(email, password)),
    signOut: (options: { preservePending?: boolean } = {}) => serialize(() => signOut(options)),
    workspace,
    storage,
    coordinator,
    storageProblem,
    offlineAccess,
  };
});

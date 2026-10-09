import { reactive, readonly } from 'vue';

// App asset readiness is transient and separate from authenticated data/sync.
export function createPwaLifecycle() {
  const state = reactive<{
    shell: 'preparing' | 'ready' | 'unavailable';
    updateAvailable: boolean;
  }>({
    shell: 'preparing',
    updateAvailable: false,
  });
  return {
    state: readonly(state),
    ready() {
      state.shell = 'ready';
    },
    updated() {
      state.updateAvailable = true;
    },
    unavailable() {
      // A failed update check does not undo an already activated offline shell.
      if (state.shell !== 'ready') state.shell = 'unavailable';
    },
  };
}
export const pwaLifecycle = createPwaLifecycle();

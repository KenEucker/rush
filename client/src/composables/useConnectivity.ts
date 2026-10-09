import { onMounted, onUnmounted, ref } from 'vue';

// Browser connectivity is a hint, never proof of Server reachability or sync.
export function useConnectivity() {
  const online = ref(navigator.onLine);
  const update = () => {
    online.value = navigator.onLine;
  };
  onMounted(() => {
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
  });
  onUnmounted(() => {
    window.removeEventListener('online', update);
    window.removeEventListener('offline', update);
  });
  return { online };
}

import { register } from 'register-service-worker';
import { pwaLifecycle } from '../src/pwa/lifecycle';

// Let updates wait until every old tab closes. Never force a reload or interrupt
// unsaved forms, and never erase Dexie while installing/updating app assets.
if (!('serviceWorker' in navigator)) {
  pwaLifecycle.unavailable();
} else {
  register(import.meta.env.QUASAR_SERVICE_WORKER_FILE, {
    registrationOptions: { updateViaCache: 'none' },
    ready() {
      pwaLifecycle.ready();
    },
    cached() {
      pwaLifecycle.ready();
    },
    updated() {
      pwaLifecycle.updated();
    },
    error() {
      pwaLifecycle.unavailable();
    },
  });
}

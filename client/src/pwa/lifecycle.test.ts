import { expect, it } from 'vitest';
import { createPwaLifecycle } from './lifecycle';

it('does not claim offline readiness before successful worker activation or caching', () => {
  const lifecycle = createPwaLifecycle();
  expect(lifecycle.state.shell).toBe('preparing');
  lifecycle.unavailable();
  expect(lifecycle.state.shell).toBe('unavailable');
  lifecycle.ready();
  expect(lifecycle.state.shell).toBe('ready');
});
it('retains an active shell when an update check fails offline', () => {
  const lifecycle = createPwaLifecycle();
  lifecycle.ready();
  lifecycle.unavailable();
  expect(lifecycle.state.shell).toBe('ready');
});
it('records a waiting update separately from offline readiness', () => {
  const lifecycle = createPwaLifecycle();
  lifecycle.ready();
  lifecycle.updated();
  expect(lifecycle.state).toEqual({ shell: 'ready', updateAvailable: true });
});

import { expect, it } from 'vitest';
import { shellNavigationAllowlist, serverNavigationDenylist } from './cachePolicy';
import routes from '../router/routes';

function shell(path: string) {
  return (
    shellNavigationAllowlist.some((pattern) => pattern.test(path)) &&
    !serverNavigationDenylist.some((pattern) => pattern.test(path))
  );
}
it('allows every current Client shell route, including query strings', () => {
  for (const route of routes[0]!.children!) {
    const path = '/' + route.path;
    expect(shell(path)).toBe(true);
    expect(shell(path + '?source=installed')).toBe(true);
  }
});
it.each([
  '/api',
  '/api/v1/session',
  '/api/v1/session?offline=1',
  '/api/v1/organizations/one/sync/pull',
  '/admin',
  '/admin/main?screen=users',
  '/sanctum/csrf-cookie',
  '/csrf-cookie',
  '/login',
  '/login?next=/',
  '/logout/',
  '/up',
  '/vendor/orchid/css/orchid.css',
  '/build/assets/app.js',
  '/storage/private.json',
  '/index.php',
  '/sw.js',
  '/workbox-runtime.js',
  '/manifest.json',
  '/unknown',
  '/account/private.json',
])('never serves cached application HTML for %s', (path) => {
  expect(shell(path)).toBe(false);
});

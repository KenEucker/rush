// Workbox caches only built, public app assets. New Client routes must explicitly
// join this allowlist; Server routes can never receive cached index.html.
export const shellNavigationAllowlist = [
  /^\/(?:\?.*)?$/,
  /^\/(?:sign-in|calendar|account|availability)\/?(?:\?.*)?$/,
];
export const serverNavigationDenylist = [
  /^\/(?:api|admin|sanctum|login|logout|csrf-cookie|up|vendor|build|storage|index\.php)(?:[^a-z]|$)/i,
];
export const precacheAssets = [
  'index.html',
  'manifest.json',
  'assets/**/*.{js,css,woff,woff2,ttf}',
  'icons/rush.{png,svg}',
];

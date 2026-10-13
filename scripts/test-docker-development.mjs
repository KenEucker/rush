import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const { chromium, expect } = createRequire(new URL('../client/package.json', import.meta.url))('@playwright/test');
const root = new URL('../', import.meta.url);
const origin = process.env.RUSH_DEV_TEST_URL ?? 'http://localhost:9188';
const project = process.env.RUSH_DEV_TEST_PROJECT ?? 'rush-dev-test';
assert.match(project, /^rush-dev-test(?:-[a-z0-9-]+)?$/, 'Use a disposable development test project.');
assert.ok(['localhost', '127.0.0.1'].includes(new URL(origin).hostname), 'Test only a local fixture.');
const containerState = () => execFileSync('docker', ['inspect', '--format', '{{.Id}} {{.RestartCount}}',
  `${project}-server-1`, `${project}-client-1`], { encoding: 'utf8' }).trim();
const before = containerState();
const id = `dev-proof-${randomUUID()}`;
const phpFile = new URL(`server/public/admin/${id}.php`, root);
const viewFile = new URL(`server/resources/views/${id}.blade.php`, root);
const moduleFile = new URL(`client/src/${id}.ts`, root);
const componentFile = new URL(`client/src/${id}.vue`, root);
const php = (version) => `<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo '${version}:'.$app->make('view')->make('${id}')->render();
`;
const component = (version) => `<template><p data-testid="dev-hmr-proof">${version}</p></template>\n`;
const module = `import { createApp } from 'vue';
import Probe from './${id}.vue';
const mount = document.createElement('div');
document.body.append(mount);
document.documentElement.dataset.rushDevProof = 'keep-page-state';
createApp(Probe).mount(mount);
`;
const browser = await chromium.launch();
try {
  await mkdir(new URL('server/public/admin/', root), { recursive: true });
  await writeFile(phpFile, php('php-one'));
  await writeFile(viewFile, 'blade-one');
  await writeFile(componentFile, component('vue-one'));
  await writeFile(moduleFile, module);
  const context = await browser.newContext({ baseURL: origin, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  const sockets = [];
  page.on('websocket', (socket) => sockets.push(socket.url()));
  await page.goto('/sign-in');
  await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible();
  await expect.poll(() => sockets.some((url) => new URL(url).host === new URL(origin).host)).toBe(true);

  await page.evaluate(async (path) => { await import(/* @vite-ignore */ path); }, `/src/${id}.ts`);
  await expect(page.getByTestId('dev-hmr-proof')).toHaveText('vue-one');
  const probeUrl = `/admin/${id}.php`;
  await expect.poll(async () => (await context.request.get(probeUrl)).text()).toBe('php-one:blade-one');
  // PHP/Blade use second-resolution mtimes. Move into the next tick before editing.
  await new Promise((resolve) => setTimeout(resolve, 1100));
  await writeFile(phpFile, php('php-two'));
  await writeFile(viewFile, 'blade-two');
  await writeFile(componentFile, component('vue-two'));
  await expect.poll(async () => (await context.request.get(probeUrl)).text()).toBe('php-two:blade-two');
  await expect(page.getByTestId('dev-hmr-proof')).toHaveText('vue-two', { timeout: 15000 });
  await expect(page.locator('html')).toHaveAttribute('data-rush-dev-proof', 'keep-page-state');
  console.log('PASS: PHP and Blade reload; Vue HMR uses the same-origin WebSocket.');

  await context.request.get('/sanctum/csrf-cookie');
  const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
  assert.ok(csrf);
  const login = await context.request.post('/login', {
    headers: { Accept: 'application/json', Origin: origin, 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) },
    data: { email: 'riley.management@example.com', password: 'password' },
  });
  assert.equal(login.status(), 200);
  assert.equal((await context.request.get('/api/v1/session', { headers: { Origin: origin } })).status(), 200);
  await page.goto('/admin');
  await expect(page.getByRole('heading', { name: 'RUSH Management', exact: true })).toBeVisible();
  assert.equal((await context.request.get('/vendor/orchid/css/orchid.css')).status(), 200);
  console.log('PASS: same-origin Sanctum session, Orchid and assets.');

  // Emulate an existing production registration; switching to development must
  // retire it without deleting pending operational data from IndexedDB.
  await page.goto('/sign-in');
  await Promise.all([page.waitForEvent('framenavigated'), page.evaluate(async () => {
    await new Promise((resolve, reject) => {
      const request = indexedDB.open('rush-dev-preservation-proof', 1);
      request.onupgradeneeded = () => request.result.createObjectStore('pending');
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const db = request.result;
        const tx = db.transaction('pending', 'readwrite');
        tx.objectStore('pending').put('retain-me', 'intent');
        tx.oncomplete = () => { db.close(); resolve(); };
      };
    });
    await navigator.serviceWorker.register('/sw.js');
  })]);
  await expect.poll(async () => page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).length)).toBe(0);
  await expect.poll(async () => page.evaluate(() => new Promise((resolve, reject) => {
    const request = indexedDB.open('rush-dev-preservation-proof', 1);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      const read = db.transaction('pending').objectStore('pending').get('intent');
      read.onsuccess = () => { db.close(); resolve(read.result); };
    };
  }))).toBe('retain-me');
  assert.equal(containerState(), before, 'Code edits must not recreate or restart the PHP or Client container.');
  console.log('PASS: worker retirement preserves IndexedDB; container IDs/restart counts unchanged.');
} finally {
  await browser.close();
  await Promise.all([phpFile, viewFile, moduleFile, componentFile].map((file) => rm(fileURLToPath(file), { force: true })));
}

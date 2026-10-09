<?php

use Composer\Semver\Semver;
use Illuminate\Support\Facades\Route;

function rush_project_path(string $path): string
{
    return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function rush_compose_service(string $compose, string $service): string
{
    $lines = preg_split('/\R/', $compose);
    $start = null;
    $end = count($lines);

    foreach ($lines as $index => $line) {
        if ($line === "  {$service}:") {
            $start = $index;

            continue;
        }

        if ($start !== null && str_starts_with($line, '  ') && ! str_starts_with($line, '    ') && str_ends_with($line, ':')) {
            $end = $index;

            break;
        }
    }

    expect($start)->not->toBeNull("Compose service [{$service}] was not found.");

    return implode("\n", array_slice($lines, $start, $end - $start));
}

it('registers the versioned API prefix for same-origin routing', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'name' => 'RUSH',
            'status' => 'ok',
        ]);

    $hasVersionedApiRoute = collect(Route::getRoutes())->contains(
        fn ($route) => $route->uri() === 'api/v1/health'
    );

    expect($hasVersionedApiRoute)->toBeTrue();
});

it('pins a PHP runtime compatible with the locked production dependencies', function () {
    $dockerfile = file_get_contents(rush_project_path('docker/server/Dockerfile'));
    $lock = json_decode(file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);

    expect(preg_match('/^FROM php:(\d+\.\d+\.\d+)-fpm\S* AS runtime\r?$/m', $dockerfile, $matches))
        ->toBe(1, 'The production PHP runtime must use a pinned version.');

    $constraints = ['root' => $lock['platform']['php']];

    foreach ($lock['packages'] as $package) {
        if (isset($package['require']['php'])) {
            $constraints[$package['name']] = $package['require']['php'];
        }
    }

    foreach ($constraints as $package => $constraint) {
        expect(Semver::satisfies($matches[1], $constraint))
            ->toBeTrue("Docker PHP {$matches[1]} does not satisfy {$package}: {$constraint}");
    }
});

it('keeps PostgreSQL private behind Caddy and the Server', function () {
    $compose = file_get_contents(rush_project_path('compose.yaml'));
    $postgres = rush_compose_service($compose, 'postgres');

    expect($compose)
        ->toContain('postgres:')
        ->toContain('image: postgres:17.2-alpine3.21')
        ->toContain('internal:')
        ->toContain('internal: true')
        ->toContain('DB_HOST: postgres');

    expect($postgres)->not->toContain('ports:');
});

it('defines Caddy as the only public entry point', function () {
    $compose = file_get_contents(rush_project_path('compose.yaml'));
    $server = rush_compose_service($compose, 'server');

    expect($compose)
        ->toContain('caddy:')
        ->toContain('"${RUSH_HTTP_PORT:-80}:80"')
        ->toContain('"${RUSH_HTTPS_PORT:-443}:443"')
        ->toContain('server:')
        ->toContain('expose:')
        ->toContain('"9000"');

    expect($server)->not->toContain('ports:');
});

it('routes Laravel paths before applying the Client SPA fallback', function () {
    $caddyfile = file_get_contents(rush_project_path('docker/caddy/Caddyfile'));

    expect($caddyfile)
        ->toContain('{$RUSH_SITE_ADDRESS:https://localhost}')
        ->toContain('@laravel path /admin* /api/v1*')
        ->toContain('php_fastcgi server:9000')
        ->toContain('try_files {path} {path}/ /index.html');

    expect(strpos($caddyfile, 'handle @laravel'))
        ->toBeLessThan(strpos($caddyfile, 'try_files {path} {path}/ /index.html'));
});

it('uses history mode so Caddy owns Client route fallback', function () {
    $quasarConfig = file_get_contents(rush_project_path('client/quasar.config.ts'));

    expect($quasarConfig)->toContain("vueRouterMode: 'history'");
});

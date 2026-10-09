<?php

use Illuminate\Support\Facades\Route;

it('boots the Laravel application shell', function () {
    config(['app.name' => 'RUSH']);

    $this->get('/')
        ->assertOk()
        ->assertViewIs('welcome')
        ->assertSee('<title>RUSH</title>', false);
});

it('registers Orchid administration routes', function () {
    $adminPrefix = trim(config('platform.prefix', 'admin'), '/');

    $hasAdminRoute = collect(Route::getRoutes())->contains(
        fn ($route) => str_starts_with($route->uri(), $adminPrefix)
    );

    expect($hasAdminRoute)->toBeTrue();
});

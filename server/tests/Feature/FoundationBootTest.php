<?php

use Illuminate\Support\Facades\Route;

it('boots the Laravel application shell', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Laravel');
});

it('registers Orchid administration routes', function () {
    $adminPrefix = trim(config('platform.prefix', 'admin'), '/');

    $hasAdminRoute = collect(Route::getRoutes())->contains(
        fn ($route) => str_starts_with($route->uri(), $adminPrefix)
    );

    expect($hasAdminRoute)->toBeTrue();
});

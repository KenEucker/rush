<?php

use App\Orchid\Screens\PhaseCoverageScreen;
use App\Orchid\Screens\PlatformScreen;
use App\Orchid\Screens\SeasonScreen;
use Illuminate\Support\Facades\Route;

Route::screen('/main', PlatformScreen::class)->name('platform.main');
Route::screen('/organizations/{organization}/phases/{phase}/coverage', PhaseCoverageScreen::class)->name('platform.phase-coverage');
Route::screen('/organizations/{organization}/seasons/{season}', SeasonScreen::class)->name('platform.seasons');

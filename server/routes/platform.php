<?php

use App\Orchid\Screens\EventScreen;
use App\Orchid\Screens\PhaseCoverageScreen;
use App\Orchid\Screens\PhaseSchedulingPolicyScreen;
use App\Orchid\Screens\PlanningInputScreen;
use App\Orchid\Screens\PlatformScreen;
use App\Orchid\Screens\SeasonScreen;
use App\Orchid\Screens\UnavailabilityScreen;
use Illuminate\Support\Facades\Route;

Route::screen('/main', PlatformScreen::class)->name('platform.main');
Route::screen('/organizations/{organization}/seasons/{season}/planning-inputs', PlanningInputScreen::class)->name('platform.planning-inputs');
Route::screen('/organizations/{organization}/seasons/{season}/events/{event}', EventScreen::class)->name('platform.events');
Route::screen('/organizations/{organization}/unavailability/{report}', UnavailabilityScreen::class)->name('platform.unavailability');
Route::screen('/organizations/{organization}/phases/{phase}/policies', PhaseSchedulingPolicyScreen::class)->name('platform.phase-policies');
Route::screen('/organizations/{organization}/phases/{phase}/coverage', PhaseCoverageScreen::class)->name('platform.phase-coverage');
Route::screen('/organizations/{organization}/seasons/{season}', SeasonScreen::class)->name('platform.seasons');

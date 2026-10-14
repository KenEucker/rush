<?php

use App\Http\Controllers\EventController;
use App\Http\Controllers\MemberProfileController;
use App\Http\Controllers\OfficialAssignmentController;
use App\Http\Controllers\PhaseCoverageController;
use App\Http\Controllers\PhaseSchedulingPolicyController;
use App\Http\Controllers\PlanningInputController;
use App\Http\Controllers\SeasonController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UnavailabilityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/organizations/{organization}/seasons/{season}/planning-inputs', [PlanningInputController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/organizations/{organization}/planning-inputs/{snapshot}', [PlanningInputController::class, 'show']);
    Route::post('/organizations/{organization}/planning-inputs/{snapshot}/evaluate', [PlanningInputController::class, 'evaluate'])->middleware('throttle:60,1');
    Route::get('/organizations/{organization}/seasons/{season}/events', [EventController::class, 'index']);
    Route::get('/organizations/{organization}/seasons/{season}/events/{event}', [EventController::class, 'show']);
    Route::put('/organizations/{organization}/seasons/{season}/events/{event}', [EventController::class, 'save'])->middleware('throttle:120,1');
    Route::get('/organizations/{organization}/unavailability', [UnavailabilityController::class, 'index']);
    Route::put('/organizations/{organization}/unavailability/{report}', [UnavailabilityController::class, 'save'])->middleware('throttle:120,1');
    Route::post('/organizations/{organization}/unavailability/{report}/acknowledge', [UnavailabilityController::class, 'acknowledge'])->middleware('throttle:120,1');
    Route::get('/organizations/{organization}/phases/{phase}/policies', [PhaseSchedulingPolicyController::class, 'show']);
    Route::put('/organizations/{organization}/phases/{phase}/policies', [PhaseSchedulingPolicyController::class, 'save'])->middleware('throttle:120,1');
    Route::get('/organizations/{organization}/phases/{phase}/coverage', [PhaseCoverageController::class, 'show']);
    Route::put('/organizations/{organization}/phases/{phase}/coverage', [PhaseCoverageController::class, 'save'])->middleware('throttle:120,1');
    Route::get('/organizations/{organization}/seasons', [SeasonController::class, 'index']);
    Route::get('/organizations/{organization}/seasons/{season}', [SeasonController::class, 'show']);
    Route::put('/organizations/{organization}/seasons/{season}', [SeasonController::class, 'save'])->middleware('throttle:120,1');
    Route::put('/organizations/{organization}/official-assignments/{assignment}', [OfficialAssignmentController::class, 'save'])->middleware('throttle:120,1');
    Route::get('/session', [SessionController::class, 'show']);
    Route::post('/organizations/{organization}/sync/push', [SyncController::class, 'push'])->middleware('throttle:120,1');
    Route::post('/organizations/{organization}/sync/pull', [SyncController::class, 'pull'])->middleware('throttle:120,1');
    Route::get('/organizations/{organization}/profiles/{profile}', [MemberProfileController::class, 'show']);
    Route::patch('/organizations/{organization}/profiles/{profile}', [MemberProfileController::class, 'update']);
});

Route::get('/health', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
]));

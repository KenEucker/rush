<?php

use App\Http\Controllers\MemberProfileController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
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

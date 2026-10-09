<?php

use App\Http\Controllers\MemberProfileController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/session', [SessionController::class, 'show']);
    Route::get('/organizations/{organization}/profiles/{profile}', [MemberProfileController::class, 'show']);
    Route::patch('/organizations/{organization}/profiles/{profile}', [MemberProfileController::class, 'update']);
});

Route::get('/health', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
]));

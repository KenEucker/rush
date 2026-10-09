<?php

use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');
Route::post('/logout', [SessionController::class, 'destroy']);
Route::redirect('/admin/login', '/sign-in')->name('platform.login');
Route::post('/admin/logout', [SessionController::class, 'destroy'])->name('platform.logout');
Route::match(['get', 'post'], '/admin/switch-logout', fn () => abort(404));

Route::get('/', function () {
    return view('welcome');
});

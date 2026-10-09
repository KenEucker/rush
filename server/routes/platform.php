<?php

use App\Orchid\Screens\PlatformScreen;
use Illuminate\Support\Facades\Route;

Route::screen('/main', PlatformScreen::class)->name('platform.main');

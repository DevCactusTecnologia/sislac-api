<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::prefix('api/auth')->group(function () {
    Route::post('/login', LoginController::class)->name('auth.login');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', LogoutController::class)->name('auth.logout');
        Route::get('/session', SessionController::class)->name('auth.session');
    });
});

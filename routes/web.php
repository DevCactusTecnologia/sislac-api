<?php

use App\Http\Controllers\Admin\AdminAuthenticateController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminLogoutController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', AdminLoginController::class)->name('login');
    Route::post('/admin/login', AdminAuthenticateController::class)
        ->middleware('throttle:5,1')
        ->name('admin.login.store');
});

Route::middleware(['auth', 'super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::post('/logout', AdminLogoutController::class)->name('logout');
    });

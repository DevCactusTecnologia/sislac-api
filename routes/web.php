<?php

use App\Http\Controllers\Admin\AdminAuthenticateController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminLogoutController;
use App\Http\Controllers\Admin\Tenants\CreateTenantController;
use App\Http\Controllers\Admin\Tenants\IndexTenantController;
use App\Http\Controllers\Admin\Tenants\StoreTenantController;
use App\Http\Controllers\Auth\AuthenticateClinicalUserController;
use App\Http\Controllers\Auth\LogoutClinicalUserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function (): void {
    Route::post('/login', AuthenticateClinicalUserController::class)
        ->middleware('throttle:5,1');

    Route::get('/admin/login', AdminLoginController::class)->name('login');
    Route::post('/admin/login', AdminAuthenticateController::class)
        ->middleware('throttle:5,1')
        ->name('admin.login.store');
});

Route::post('/logout', LogoutClinicalUserController::class)
    ->middleware('auth:sanctum');

Route::middleware(['auth', 'super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::post('/logout', AdminLogoutController::class)->name('logout');

        Route::get('/laboratorios', IndexTenantController::class)->name('tenants.index');
        Route::get('/laboratorios/novo', CreateTenantController::class)->name('tenants.create');
        Route::post('/laboratorios', StoreTenantController::class)->name('tenants.store');
    });

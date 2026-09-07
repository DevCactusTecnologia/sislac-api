<?php

use App\Http\Controllers\Atendimentos\StoreAtendimentoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Pacientes\CreatePacienteController;
use App\Http\Controllers\Pacientes\ListPacientesController;
use App\Http\Controllers\Pacientes\ShowPacienteController;
use App\Http\Controllers\Pacientes\UpdatePacienteController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::prefix('auth')->group(function () {
    Route::post('/login', LoginController::class)->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', LogoutController::class)->name('auth.logout');
        Route::get('/session', SessionController::class)->name('auth.session');
    });
});

Route::middleware(['auth:sanctum', 'tenant', 'tenant.permission:visualizar_pacientes'])
    ->prefix('pacientes')
    ->group(function () {
        Route::get('/', ListPacientesController::class)->name('pacientes.index');
        Route::get('/{id}', ShowPacienteController::class)->whereNumber('id')->name('pacientes.show');
    });

Route::middleware(['auth:sanctum', 'tenant', 'tenant.permission:cadastrar_paciente'])
    ->post('/pacientes', CreatePacienteController::class)
    ->name('pacientes.store');

Route::middleware(['auth:sanctum', 'tenant', 'tenant.permission:editar_paciente'])
    ->patch('/pacientes/{id}', UpdatePacienteController::class)
    ->whereNumber('id')
    ->name('pacientes.update');

Route::middleware(['auth:sanctum', 'tenant', 'tenant.permission:criar_atendimento'])
    ->post('/atendimentos', StoreAtendimentoController::class)
    ->name('atendimentos.store');

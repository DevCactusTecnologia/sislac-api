<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Pacientes\CreatePacienteController;
use App\Http\Controllers\Pacientes\ListPacientesController;
use App\Http\Controllers\Pacientes\ShowPacienteController;
use App\Http\Controllers\Pacientes\UpdatePacienteController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:visualizar_pacientes'])
    ->prefix('pacientes')
    ->group(function () {
        Route::get('/', ListPacientesController::class)->name('pacientes.index');
        Route::get('/{id}', ShowPacienteController::class)->whereNumber('id')->name('pacientes.show');
    });

Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:cadastrar_paciente'])
    ->post('/pacientes', CreatePacienteController::class)
    ->name('pacientes.store');

Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:editar_paciente'])
    ->patch('/pacientes/{id}', UpdatePacienteController::class)
    ->whereNumber('id')
    ->name('pacientes.update');

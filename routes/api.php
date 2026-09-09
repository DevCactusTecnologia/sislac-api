<?php

use App\Http\Controllers\Atendimentos\AtendimentoKpisController;
use App\Http\Controllers\Atendimentos\ListAtendimentosController;
use App\Http\Controllers\Atendimentos\ShowAtendimentoByProtocoloController;
use App\Http\Controllers\Atendimentos\ShowAtendimentoController;
use App\Http\Controllers\Atendimentos\StoreAtendimentoController;
use App\Http\Controllers\Atendimentos\UpdateAtendimentoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Pacientes\CreatePacienteController;
use App\Http\Controllers\Pacientes\ListPacientesController;
use App\Http\Controllers\Pacientes\ShowPacienteController;
use App\Http\Controllers\Pacientes\UpdatePacienteController;
use App\Http\Controllers\Rotina\ShowRotinaConfigController;
use App\Http\Controllers\Rotina\UpdateRotinaConfigController;
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

Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:visualizar_atendimentos'])
    ->prefix('atendimentos')
    ->group(function () {
        Route::get('/', ListAtendimentosController::class)->name('atendimentos.index');
        Route::get('/kpis', AtendimentoKpisController::class)->name('atendimentos.kpis');
        Route::get('/protocolo/{protocolo}', ShowAtendimentoByProtocoloController::class)
            ->where('protocolo', '[0-9]{7}')
            ->name('atendimentos.show-protocolo');
        Route::get('/{id}', ShowAtendimentoController::class)
            ->whereNumber('id')
            ->name('atendimentos.show');
    });

Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:criar_atendimento'])
    ->post('/atendimentos', StoreAtendimentoController::class)
    ->name('atendimentos.store');

Route::middleware(['supabase.auth', 'tenant'])
    ->patch('/atendimentos/{id}', UpdateAtendimentoController::class)
    ->whereNumber('id')
    ->name('atendimentos.update');

Route::middleware(['supabase.auth', 'tenant'])
    ->get('/rotina/config', ShowRotinaConfigController::class)
    ->name('rotina.config.show');

Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:configuracoes_sistema'])
    ->patch('/rotina/config', UpdateRotinaConfigController::class)
    ->name('rotina.config.update');

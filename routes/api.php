<?php

use App\Http\Controllers\Atendimentos\AtendimentoKpisController;
use App\Http\Controllers\Atendimentos\ListAtendimentosController;
use App\Http\Controllers\Atendimentos\ShowAtendimentoByProtocoloController;
use App\Http\Controllers\Atendimentos\ShowAtendimentoController;
use App\Http\Controllers\Atendimentos\StoreAtendimentoController;
use App\Http\Controllers\Atendimentos\UpdateAtendimentoController;
use App\Http\Controllers\Financeiro\CloseCaixaController;
use App\Http\Controllers\Financeiro\CreateFinanceiroSaidaController;
use App\Http\Controllers\Financeiro\ListAReceberPacientesController;
use App\Http\Controllers\Financeiro\ListFinanceiroSaidasController;
use App\Http\Controllers\Financeiro\ListRecebimentosPacientesController;
use App\Http\Controllers\Financeiro\OpenCaixaController;
use App\Http\Controllers\Financeiro\RegisterPacientePaymentController;
use App\Http\Controllers\Financeiro\ReverseFinanceiroSaidaController;
use App\Http\Controllers\Financeiro\ReversePacientePaymentController;
use App\Http\Controllers\Financeiro\ShowOpenCaixaController;
use App\Http\Controllers\Financeiro\UpdateFinanceiroSaidaController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Pacientes\CreatePacienteController;
use App\Http\Controllers\Pacientes\ListPacientesController;
use App\Http\Controllers\Pacientes\ShowPacienteController;
use App\Http\Controllers\Pacientes\UpdatePacienteController;
use App\Http\Controllers\Rotina\ListRotinaAnaliseController;
use App\Http\Controllers\Rotina\ListRotinaColetaController;
use App\Http\Controllers\Rotina\ShowRotinaConfigController;
use App\Http\Controllers\Rotina\TransitionRotinaExameController;
use App\Http\Controllers\Rotina\UpdateRotinaConfigController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:visualizar_pacientes'])
    ->prefix('pacientes')
    ->group(function () {
        Route::get('/', ListPacientesController::class)->name('pacientes.index');
        Route::get('/{id}', ShowPacienteController::class)->whereNumber('id')->name('pacientes.show');
    });

Route::middleware(['supabase.auth', 'supabase.db', 'permission:cadastrar_paciente'])
    ->post('/pacientes', CreatePacienteController::class)
    ->name('pacientes.store');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:editar_paciente'])
    ->patch('/pacientes/{id}', UpdatePacienteController::class)
    ->whereNumber('id')
    ->name('pacientes.update');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:visualizar_atendimentos'])
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

Route::middleware(['supabase.auth', 'supabase.db', 'permission:criar_atendimento'])
    ->post('/atendimentos', StoreAtendimentoController::class)
    ->name('atendimentos.store');

Route::middleware(['supabase.auth', 'supabase.db'])
    ->patch('/atendimentos/{id}', UpdateAtendimentoController::class)
    ->whereNumber('id')
    ->name('atendimentos.update');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:visualizar_atendimentos'])
    ->get('/financeiro/a-receber/pacientes', ListAReceberPacientesController::class)
    ->name('financeiro.a-receber.pacientes');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:visualizar_atendimentos'])
    ->get('/financeiro/recebimentos/pacientes', ListRecebimentosPacientesController::class)
    ->name('financeiro.recebimentos.pacientes');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:registrar_pagamento'])
    ->post('/financeiro/atendimentos/{id}/pagamentos', RegisterPacientePaymentController::class)
    ->whereNumber('id')
    ->name('financeiro.pagamentos.store');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:gestao_financeira'])
    ->post('/financeiro/pagamentos/{id}/estorno', ReversePacientePaymentController::class)
    ->whereNumber('id')
    ->name('financeiro.pagamentos.estorno');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:visualizar_financeiro'])
    ->get('/financeiro/saidas', ListFinanceiroSaidasController::class)
    ->name('financeiro.saidas.index');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:gestao_financeira'])
    ->post('/financeiro/saidas', CreateFinanceiroSaidaController::class)
    ->name('financeiro.saidas.store');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:gestao_financeira'])
    ->patch('/financeiro/saidas/{id}', UpdateFinanceiroSaidaController::class)
    ->whereNumber('id')
    ->name('financeiro.saidas.update');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:gestao_financeira'])
    ->post('/financeiro/saidas/{id}/estorno', ReverseFinanceiroSaidaController::class)
    ->whereNumber('id')
    ->name('financeiro.saidas.estorno');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:visualizar_financeiro'])
    ->get('/financeiro/caixa/aberto', ShowOpenCaixaController::class)
    ->name('financeiro.caixa.aberto');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:gestao_financeira'])
    ->post('/financeiro/caixa/abrir', OpenCaixaController::class)
    ->name('financeiro.caixa.abrir');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:gestao_financeira'])
    ->post('/financeiro/caixa/{id}/fechar', CloseCaixaController::class)
    ->whereNumber('id')
    ->name('financeiro.caixa.fechar');

Route::middleware(['supabase.auth', 'supabase.db'])
    ->get('/rotina/config', ShowRotinaConfigController::class)
    ->name('rotina.config.show');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:configuracoes_sistema'])
    ->patch('/rotina/config', UpdateRotinaConfigController::class)
    ->name('rotina.config.update');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:visualizar_atendimentos'])
    ->get('/rotina/coleta', ListRotinaColetaController::class)
    ->name('rotina.coleta.index');

Route::middleware(['supabase.auth', 'supabase.db', 'permission:visualizar_atendimentos'])
    ->get('/rotina/analise', ListRotinaAnaliseController::class)
    ->name('rotina.analise.index');

Route::middleware(['supabase.auth', 'supabase.db'])
    ->post('/rotina/exames/{id}/transicao', TransitionRotinaExameController::class)
    ->whereNumber('id')
    ->name('rotina.exames.transition');

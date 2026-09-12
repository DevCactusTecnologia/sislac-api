<?php

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Models\AtendimentoExame;
use App\Domain\Atendimentos\Models\AtendimentoPagamento;
use App\Domain\Financeiro\Models\CaixaSessao;
use App\Domain\Financeiro\Models\FinanceiroEstorno;
use App\Domain\Financeiro\Models\FinanceiroSaida;
use App\Domain\Pacientes\Models\Paciente;

it('mantém todos os models operacionais no banco do laboratório autenticado', function (string $modelClass) {
    expect((new $modelClass)->getConnectionName())->toBe('lab');
})->with([
    Atendimento::class,
    AtendimentoExame::class,
    AtendimentoPagamento::class,
    CaixaSessao::class,
    FinanceiroEstorno::class,
    FinanceiroSaida::class,
    Paciente::class,
]);

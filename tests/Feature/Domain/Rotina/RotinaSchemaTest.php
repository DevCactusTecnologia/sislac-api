<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    resetSupabaseFixture();
});

function rotinaSetMode(string $mode): void
{
    DB::table('lab_config')->where('singleton_key', 1)->update([
        'rotina_fluxo_modo' => $mode,
    ]);
}

function rotinaCreateAtendimento(): int
{
    return (int) DB::table('atendimentos')->insertGetId([
        'paciente_nome' => 'Paciente Rotina',
        'paciente_cpf' => '',
    ]);
}

/** @param array<string, mixed> $attributes */
function rotinaCreateExame(int $atendimentoId, array $attributes = []): int
{
    return (int) DB::table('atendimento_exames')->insertGetId(array_merge([
        'atendimento_id' => $atendimentoId,
        'nome_exame' => 'Hemograma '.Str::lower(Str::random(6)),
        'status' => 'pendente',
        'tipo_processo' => 'INTERNO',
    ], $attributes));
}

it('mantém configuração singleton da rotina com modo completo por padrão', function () {
    expect(Schema::hasTable('lab_config'))->toBeTrue()
        ->and(DB::table('lab_config')->count())->toBe(1)
        ->and(DB::table('lab_config')->value('rotina_fluxo_modo'))->toBe('completo');
});

it('impede segundo registro de configuração', function () {
    DB::table('lab_config')->insert([
        'singleton_key' => 1,
        'rotina_fluxo_modo' => 'completo',
    ]);
})->throws(QueryException::class);

it('aceita somente os três modos canônicos da rotina', function () {
    DB::table('lab_config')->where('singleton_key', 1)->update([
        'rotina_fluxo_modo' => 'invalido',
    ]);
})->throws(QueryException::class);

it('bloqueia salto direto de pendente para analisado no modo completo', function () {
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    DB::table('atendimento_exames')->where('id', $exameId)->update([
        'status' => 'analisado',
    ]);
})->throws(QueryException::class);

it('permite a sequência operacional completa', function () {
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    foreach (['coletado', 'em_bancada', 'analisado'] as $status) {
        DB::table('atendimento_exames')->where('id', $exameId)->update([
            'status' => $status,
        ]);
    }

    expect(DB::table('atendimento_exames')->where('id', $exameId)->value('status'))
        ->toBe('analisado');
});

it('encurta coleta para analisado no modo coleta resultado', function () {
    rotinaSetMode('coleta_resultado');
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    DB::table('atendimento_exames')->where('id', $exameId)->update([
        'status' => 'coletado',
    ]);

    $exame = DB::table('atendimento_exames')->where('id', $exameId)->first();

    expect($exame?->status)->toBe('analisado')
        ->and($exame?->data_coleta)->not->toBeNull()
        ->and($exame?->data_analise)->not->toBeNull()
        ->and($exame?->analista)->toBe('__SEM_REGISTRO__');
});

it('não materializa bancada no modo coleta resultado', function () {
    rotinaSetMode('coleta_resultado');
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    DB::table('atendimento_exames')->where('id', $exameId)->update([
        'status' => 'em_bancada',
    ]);
})->throws(QueryException::class);

it('short circuita novo exame interno no modo apenas resultado', function () {
    rotinaSetMode('apenas_resultado');
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    $exame = DB::table('atendimento_exames')->where('id', $exameId)->first();

    expect($exame?->status)->toBe('analisado')
        ->and($exame?->data_coleta)->not->toBeNull()
        ->and($exame?->data_analise)->not->toBeNull()
        ->and($exame?->coletor)->toBe('__SEM_REGISTRO__')
        ->and($exame?->analista)->toBe('__SEM_REGISTRO__');
});

it('preserva exame terceirizado digitado nos modos encurtados', function () {
    rotinaSetMode('apenas_resultado');
    $exameId = rotinaCreateExame(rotinaCreateAtendimento(), [
        'status' => 'digitado',
        'tipo_processo' => 'TERCEIRIZADO',
    ]);

    $exame = DB::table('atendimento_exames')->where('id', $exameId)->first();

    expect($exame?->status)->toBe('digitado')
        ->and($exame?->data_coleta)->toBeNull()
        ->and($exame?->data_analise)->toBeNull();
});

it('impede regressão de exame finalizado', function () {
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    foreach (['coletado', 'em_bancada', 'analisado', 'finalizado'] as $status) {
        DB::table('atendimento_exames')->where('id', $exameId)->update(['status' => $status]);
    }

    DB::table('atendimento_exames')->where('id', $exameId)->update([
        'status' => 'pendente',
    ]);
})->throws(QueryException::class);

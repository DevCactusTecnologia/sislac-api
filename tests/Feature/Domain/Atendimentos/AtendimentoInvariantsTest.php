<?php

use App\Models\Laboratory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentoInvariantDatabase = 'sislac_t_atinv_'.Str::lower(Str::random(10));
    atendimentoInvariantControlConnection()->exec('CREATE DATABASE "'.$this->atendimentoInvariantDatabase.'"');

    $laboratoryId = (string) Str::uuid();
    $now = now();

    createTestLaboratory([
        'id' => $laboratoryId,
        'name' => 'Laboratório Invariantes',
        'code' => 'atinv-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentoInvariantDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $laboratory = Laboratory::query()->findOrFail($laboratoryId);
    connectTestLaboratory($laboratory);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
    atendimentoInvariantControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentoInvariantDatabase.'" WITH (FORCE)');
});

function atendimentoInvariantControlConnection(?string $database = null): PDO
{
    $config = config('database.connections.central');
    $database ??= 'postgres';

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $database),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function createInvariantAtendimento(array $overrides = []): int
{
    return (int) DB::table('atendimentos')->insertGetId(array_merge([
        'protocolo' => 'CLIENT-'.Str::upper(Str::random(8)),
        'paciente_nome' => 'Paciente Invariantes',
        'paciente_cpf' => '12345678901',
    ], $overrides));
}

it('gera protocolo de sete dígitos no banco mesmo quando o cliente não envia protocolo', function () {
    $id = (int) DB::table('atendimentos')->insertGetId([
        'paciente_nome' => 'Paciente Sem Protocolo',
        'paciente_cpf' => '11122233344',
    ]);

    $protocolo = (string) DB::table('atendimentos')->where('id', $id)->value('protocolo');

    expect($protocolo)->toMatch('/^\d{7}$/');
});

it('ignora protocolo enviado pelo cliente', function () {
    $id = createInvariantAtendimento(['protocolo' => 'PROTOCOLO-CLIENTE']);
    $protocolo = (string) DB::table('atendimentos')->where('id', $id)->value('protocolo');

    expect($protocolo)->not->toBe('PROTOCOLO-CLIENTE')
        ->and($protocolo)->toMatch('/^\d{7}$/');
});

it('torna o protocolo imutável depois da criação', function () {
    $id = createInvariantAtendimento();

    expect(fn () => DB::table('atendimentos')->where('id', $id)->update(['protocolo' => '9999999']))
        ->toThrow(QueryException::class);
});

it('impede duplicação da mesma idempotency_key', function () {
    $key = (string) Str::uuid();

    createInvariantAtendimento(['idempotency_key' => $key]);

    expect(fn () => createInvariantAtendimento([
        'idempotency_key' => $key,
        'paciente_cpf' => '22233344455',
    ]))->toThrow(QueryException::class);
});

it('recalcula status e totais quando exames mudam', function () {
    $id = createInvariantAtendimento();

    DB::table('atendimento_exames')->insert([
        'atendimento_id' => $id,
        'nome_exame' => 'Hemograma',
        'status' => 'pendente',
        'valor' => 80,
        'valor_original' => 100,
        'ordem' => 1,
    ]);

    $atendimento = DB::table('atendimentos')->where('id', $id)->first();

    expect($atendimento?->status_atendimento)->toBe('Pedido Realizado')
        ->and((float) $atendimento?->subtotal)->toBe(100.0)
        ->and((float) $atendimento?->total)->toBe(80.0)
        ->and((float) $atendimento?->desconto_total)->toBe(20.0)
        ->and((float) $atendimento?->acrescimo_total)->toBe(0.0);

    foreach (['coletado', 'em_bancada', 'analisado', 'finalizado'] as $status) {
        DB::table('atendimento_exames')->where('atendimento_id', $id)->update(['status' => $status]);
    }

    expect(DB::table('atendimentos')->where('id', $id)->value('status_atendimento'))
        ->toBe('Resultado Liberado');
});

it('deriva pagamento parcial e efetuado a partir dos pagamentos ativos', function () {
    $id = createInvariantAtendimento();

    DB::table('atendimento_exames')->insert([
        'atendimento_id' => $id,
        'nome_exame' => 'Glicose',
        'status' => 'pendente',
        'valor' => 100,
        'valor_original' => 100,
        'ordem' => 1,
    ]);

    DB::table('atendimento_pagamentos')->insert([
        'atendimento_id' => $id,
        'tipo' => 'PIX',
        'valor' => 30,
    ]);

    expect(DB::table('atendimentos')->where('id', $id)->value('status_pagamento'))
        ->toBe('Pagamento parcial');

    DB::table('atendimento_pagamentos')->insert([
        'atendimento_id' => $id,
        'tipo' => 'Dinheiro',
        'valor' => 70,
    ]);

    expect(DB::table('atendimentos')->where('id', $id)->value('status_pagamento'))
        ->toBe('Pagamento efetuado');
});

it('marca faturado ao convênio quando todos os exames ativos pertencem ao convênio', function () {
    $id = createInvariantAtendimento();

    DB::table('atendimento_exames')->insert([
        'atendimento_id' => $id,
        'nome_exame' => 'TSH',
        'status' => 'pendente',
        'valor' => 60,
        'valor_original' => 60,
        'ordem' => 1,
        'cobranca_destino' => 'convenio',
        'convenio_cobranca_id' => 10,
    ]);

    expect(DB::table('atendimentos')->where('id', $id)->value('status_pagamento'))
        ->toBe('Faturado ao convênio');
});

it('deriva cancelamento quando todos os exames são cancelados', function () {
    $id = createInvariantAtendimento();

    DB::table('atendimento_exames')->insert([
        'atendimento_id' => $id,
        'nome_exame' => 'PCR',
        'status' => 'pendente',
        'valor' => 50,
        'valor_original' => 50,
        'ordem' => 1,
    ]);

    DB::table('atendimento_exames')->where('atendimento_id', $id)->update(['status' => 'cancelado']);

    $atendimento = DB::table('atendimentos')->where('id', $id)->first();

    expect($atendimento?->status_atendimento)->toBe('Cancelado')
        ->and($atendimento?->status_pagamento)->toBe('Pagamento cancelado');
});

it('registra auditoria de criação do atendimento', function () {
    $id = createInvariantAtendimento();

    $audit = DB::table('atendimento_audit')
        ->where('atendimento_id', $id)
        ->where('entidade', 'atendimentos')
        ->where('operacao', 'INSERT')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->new_value)->not->toBeNull();
});

it('impede atualização de registros de auditoria', function () {
    $id = createInvariantAtendimento();
    $auditId = DB::table('atendimento_audit')->where('atendimento_id', $id)->value('id');

    expect($auditId)->not->toBeNull();

    expect(fn () => DB::table('atendimento_audit')->where('id', $auditId)->update(['acao' => 'adulterada']))
        ->toThrow(QueryException::class);
});

it('impede exclusão de registros de auditoria', function () {
    $id = createInvariantAtendimento();
    $auditId = DB::table('atendimento_audit')->where('atendimento_id', $id)->value('id');

    expect($auditId)->not->toBeNull();

    expect(fn () => DB::table('atendimento_audit')->where('id', $auditId)->delete())
        ->toThrow(QueryException::class);
});

<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentosDatabase = 'sislac_t_atend_'.Str::lower(Str::random(10));
    atendimentoSchemaControlConnection()->exec('CREATE DATABASE "'.$this->atendimentosDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Atendimentos',
        'code' => 'atend-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentosDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->atendimentosTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->atendimentosTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    atendimentoSchemaControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentosDatabase.'" WITH (FORCE)');
});

function atendimentoSchemaControlConnection(?string $database = null): PDO
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

it('cria as três tabelas físicas do fluxo de atendimento', function () {
    expect(Schema::hasColumns('atendimentos', [
        'id', 'protocolo', 'data', 'paciente_id', 'paciente_nome', 'paciente_cpf',
        'paciente_nascimento', 'solicitante', 'convenio_id', 'convenio_nome', 'unidade_id',
        'status_atendimento', 'status_pagamento', 'motivo_cancelamento', 'assinatura_protocolo',
        'origem_atendimento', 'tem_retificacao', 'guia_numero', 'guia_data', 'subtotal',
        'desconto_total', 'acrescimo_total', 'total', 'jejum', 'observacoes_assistente',
        'idempotency_key', 'risco_cardiovascular', 'prioridade_clinica', 'senha_consulta',
        'senha_consulta_expira_em', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('atendimento_exames', [
            'id', 'atendimento_id', 'exame_id', 'nome_exame', 'status', 'valor', 'valor_original',
            'ordem', 'cobranca_destino', 'convenio_cobranca_id', 'amostra_seq', 'grupo_exame_id',
            'material_id', 'tipo_processo', 'lab_apoio_id', 'solicitante', 'resultados',
            'motivo_cancelamento', 'data_coleta', 'data_analise', 'data_liberacao',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('atendimento_pagamentos', [
            'id', 'atendimento_id', 'tipo', 'valor', 'data', 'observacao', 'status_pagamento',
            'caixa_sessao_id', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('preserva as constraints locais do contrato Supabase', function () {
    $pdo = atendimentoSchemaControlConnection($this->atendimentosDatabase);

    expect(fn () => $pdo->exec("INSERT INTO atendimentos (protocolo, paciente_nome, paciente_cpf, origem_atendimento) VALUES ('0000001', 'Paciente', '', 'OUTRO')"))
        ->toThrow(PDOException::class);

    expect(fn () => $pdo->exec("INSERT INTO atendimentos (protocolo, paciente_nome, paciente_cpf, prioridade_clinica) VALUES ('0000002', 'Paciente', '', 'alta')"))
        ->toThrow(PDOException::class);
});

it('mantém protocolo único e filhos ligados ao atendimento', function () {
    $pdo = atendimentoSchemaControlConnection($this->atendimentosDatabase);
    $pdo->exec("INSERT INTO atendimentos (protocolo, paciente_nome, paciente_cpf) VALUES ('0000001', 'Paciente 1', '')");

    expect(fn () => $pdo->exec("INSERT INTO atendimentos (protocolo, paciente_nome, paciente_cpf) VALUES ('0000001', 'Paciente 2', '')"))
        ->toThrow(PDOException::class);

    expect(fn () => $pdo->exec("INSERT INTO atendimento_exames (atendimento_id, nome_exame) VALUES (999999, 'Hemograma')"))
        ->toThrow(PDOException::class);

    expect(fn () => $pdo->exec("INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor) VALUES (999999, 'Dinheiro', 10)"))
        ->toThrow(PDOException::class);
});

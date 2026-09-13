<?php

use App\Models\Laboratory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentosDatabase = 'sislac_t_atendimentos_'.Str::lower(Str::random(10));
    atendimentoSchemaControlConnection()->exec('CREATE DATABASE "'.$this->atendimentosDatabase.'"');

    $laboratoryId = (string) Str::uuid();
    $now = now();

    createTestLaboratory([
        'id' => $laboratoryId,
        'name' => 'Laboratório Atendimentos',
        'code' => 'atendimentos-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentosDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->atendimentosLaboratory = Laboratory::query()->findOrFail($laboratoryId);
    connectTestLaboratory($this->atendimentosLaboratory);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
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

it('cria as tabelas físicas do agregado no banco tenant', function () {
    expect(Schema::hasTable('protocolo_sequence'))->toBeTrue()
        ->and(Schema::hasTable('atendimentos'))->toBeTrue()
        ->and(Schema::hasTable('atendimento_exames'))->toBeTrue()
        ->and(Schema::hasTable('atendimento_pagamentos'))->toBeTrue()
        ->and(Schema::hasTable('atendimento_audit'))->toBeTrue();
});

it('cria o contrato físico principal de atendimentos sem tenant_id', function () {
    expect(Schema::hasColumns('atendimentos', [
        'id', 'protocolo', 'data', 'paciente_id', 'paciente_nome', 'paciente_cpf',
        'paciente_nascimento', 'solicitante', 'convenio_id', 'convenio_nome', 'unidade_id',
        'status_atendimento', 'status_pagamento', 'motivo_cancelamento', 'assinatura_protocolo',
        'origem_atendimento', 'tem_retificacao', 'guia_numero', 'guia_data', 'subtotal',
        'desconto_total', 'acrescimo_total', 'total', 'jejum', 'observacoes_assistente',
        'idempotency_key', 'risco_cardiovascular', 'prioridade_clinica', 'senha_consulta',
        'senha_consulta_expira_em', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('atendimentos', 'tenant_id'))->toBeFalse();
});

it('preserva as colunas estruturais atuais de exames e pagamentos', function () {
    expect(Schema::hasColumns('atendimento_exames', [
        'id', 'atendimento_id', 'exame_id', 'nome_exame', 'status', 'valor', 'valor_original',
        'analista', 'coletor', 'data_coleta', 'data_analise', 'data_liberacao', 'resultados',
        'motivo_cancelamento', 'ordem', 'tipo_processo', 'lab_apoio_id', 'integracao_ativa',
        'status_externo', 'protocolo_externo', 'data_envio', 'data_retorno', 'resultado_importado',
        'arquivo_resultado_path', 'cobranca_destino', 'convenio_cobranca_id', 'amostra_seq',
        'grupo_exame_id', 'amostra_id', 'is_reutilizacao', 'pop_versao', 'pop_id', 'solicitante',
        'pdf_override_url', 'pdf_override_uploaded_by', 'pdf_override_uploaded_at',
        'pdf_override_motivo', 'pdf_override_replaced_path', 'metodologia_snapshot',
        'unidade_snapshot', 'retificado', 'retificado_at', 'material_id', 'mnemonico_exame',
        'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('atendimento_pagamentos', [
            'id', 'atendimento_id', 'tipo', 'valor', 'data', 'observacao',
            'status_pagamento', 'caixa_sessao_id', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('usa tipos postgres para uuid e jsonb onde o contrato exige', function () {
    $pdo = atendimentoSchemaControlConnection($this->atendimentosDatabase);
    $statement = $pdo->query(<<<'SQL'
        SELECT table_name, column_name, udt_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND (
            (table_name = 'atendimentos' AND column_name = 'idempotency_key')
            OR (table_name = 'atendimento_exames' AND column_name IN ('exame_id', 'grupo_exame_id', 'resultados'))
          )
        ORDER BY table_name, column_name
    SQL);

    $types = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $types[$row['table_name'].'.'.$row['column_name']] = $row['udt_name'];
    }

    expect($types)->toMatchArray([
        'atendimentos.idempotency_key' => 'uuid',
        'atendimento_exames.exame_id' => 'uuid',
        'atendimento_exames.grupo_exame_id' => 'uuid',
        'atendimento_exames.resultados' => 'jsonb',
    ]);
});

<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->financeiroSchemaDatabase = 'sislac_t_fin_schema_'.Str::lower(Str::random(9));
    financeiroSchemaControlConnection()->exec('CREATE DATABASE "'.$this->financeiroSchemaDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Financeiro Schema',
        'code' => 'fin-schema-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->financeiroSchemaDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->financeiroSchemaTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->financeiroSchemaTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    financeiroSchemaControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->financeiroSchemaDatabase.'" WITH (FORCE)');
});

function financeiroSchemaControlConnection(?string $database = null): PDO
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

/** @return array{atendimento_id:int,pagamento_id:int} */
function financeiroSchemaSeedPayment(PDO $pdo): array
{
    $atendimento = $pdo->query(<<<'SQL'
        INSERT INTO atendimentos (protocolo, paciente_nome, paciente_cpf)
        VALUES ('TEMP', 'Paciente Schema', '12345678901')
        RETURNING id
    SQL)?->fetchColumn();

    if ($atendimento === false) {
        throw new RuntimeException('Falha ao criar atendimento de teste.');
    }

    $atendimentoId = (int) $atendimento;
    $pdo->exec("INSERT INTO atendimento_exames (atendimento_id, nome_exame, valor, valor_original) VALUES ({$atendimentoId}, 'Hemograma', 100, 100)");
    $pagamento = $pdo->query("INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor) VALUES ({$atendimentoId}, 'PIX', 50) RETURNING id")?->fetchColumn();

    if ($pagamento === false) {
        throw new RuntimeException('Falha ao criar pagamento de teste.');
    }

    return ['atendimento_id' => $atendimentoId, 'pagamento_id' => (int) $pagamento];
}

it('cria contrato de financeiro_estornos compatível com o núcleo do baseline', function () {
    $pdo = financeiroSchemaControlConnection($this->financeiroSchemaDatabase);

    $columns = $pdo->query(<<<'SQL'
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'financeiro_estornos'
        ORDER BY ordinal_position
    SQL)?->fetchAll(PDO::FETCH_COLUMN);

    expect($columns)->toBe([
        'id',
        'origem_tipo',
        'origem_id',
        'motivo',
        'valor',
        'criado_por',
        'criado_em',
        'created_at',
        'updated_at',
    ]);

    $unique = (int) $pdo->query(<<<'SQL'
        SELECT count(*)
        FROM pg_indexes
        WHERE schemaname = 'public'
          AND tablename = 'financeiro_estornos'
          AND indexname = 'uq_financeiro_estornos_origem'
          AND indexdef LIKE '%UNIQUE%origem_tipo, origem_id%'
    SQL)?->fetchColumn();

    expect($unique)->toBe(1);
});

it('impede motivo vazio e segundo estorno pela integridade do banco', function () {
    $pdo = financeiroSchemaControlConnection($this->financeiroSchemaDatabase);
    $seed = financeiroSchemaSeedPayment($pdo);
    $userId = (string) Str::uuid();

    expect(fn () => $pdo->exec("INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor, criado_por) VALUES ('pagamento', {$seed['pagamento_id']}, '   ', 50, '{$userId}')"))
        ->toThrow(PDOException::class);

    $pdo->exec("INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor, criado_por) VALUES ('pagamento', {$seed['pagamento_id']}, 'Correção', 50, '{$userId}')");

    expect(fn () => $pdo->exec("INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor, criado_por) VALUES ('pagamento', {$seed['pagamento_id']}, 'Duplicado', 50, '{$userId}')"))
        ->toThrow(PDOException::class);
});

it('mantém financeiro_estornos append-only', function () {
    $pdo = financeiroSchemaControlConnection($this->financeiroSchemaDatabase);
    $seed = financeiroSchemaSeedPayment($pdo);
    $userId = (string) Str::uuid();
    $estornoId = (int) $pdo->query("INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor, criado_por) VALUES ('pagamento', {$seed['pagamento_id']}, 'Correção', 50, '{$userId}') RETURNING id")?->fetchColumn();

    expect(fn () => $pdo->exec("UPDATE financeiro_estornos SET motivo = 'Alterado' WHERE id = {$estornoId}"))
        ->toThrow(PDOException::class)
        ->and(fn () => $pdo->exec("DELETE FROM financeiro_estornos WHERE id = {$estornoId}"))
        ->toThrow(PDOException::class);
});

it('proíbe DELETE físico de pagamento e orienta uso de estorno', function () {
    $pdo = financeiroSchemaControlConnection($this->financeiroSchemaDatabase);
    $seed = financeiroSchemaSeedPayment($pdo);

    expect(fn () => $pdo->exec("DELETE FROM atendimento_pagamentos WHERE id = {$seed['pagamento_id']}"))
        ->toThrow(PDOException::class);

    expect((int) $pdo->query("SELECT count(*) FROM atendimento_pagamentos WHERE id = {$seed['pagamento_id']}")?->fetchColumn())->toBe(1);
});

it('impede sobrepagamento mesmo por escrita direta no banco', function () {
    $pdo = financeiroSchemaControlConnection($this->financeiroSchemaDatabase);
    $seed = financeiroSchemaSeedPayment($pdo);

    expect(fn () => $pdo->exec("INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor) VALUES ({$seed['atendimento_id']}, 'Dinheiro', 50.01)"))
        ->toThrow(PDOException::class);

    expect((string) $pdo->query("SELECT COALESCE(SUM(valor), 0)::numeric(14,2) FROM atendimento_pagamentos WHERE atendimento_id = {$seed['atendimento_id']} AND COALESCE(status_pagamento, 'efetuado') <> 'estornado'")?->fetchColumn())->toBe('50.00');
});

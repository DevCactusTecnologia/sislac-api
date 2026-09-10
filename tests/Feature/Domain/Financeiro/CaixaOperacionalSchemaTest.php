<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->caixaSchemaDatabase = 'sislac_t_caixa_schema_'.Str::lower(Str::random(7));
    caixaSchemaControlConnection()->exec('CREATE DATABASE "'.$this->caixaSchemaDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Schema Caixa',
        'code' => 'caixa-schema-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'database_name' => $this->caixaSchemaDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $tenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($tenant);

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
    caixaSchemaControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->caixaSchemaDatabase.'" WITH (FORCE)');
});

function caixaSchemaControlConnection(?string $database = null): PDO
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

it('cria caixa e dependência de saídas sem tenant_id duplicado', function () {
    $pdo = caixaSchemaControlConnection($this->caixaSchemaDatabase);

    expect((string) $pdo->query("SELECT to_regclass('public.caixa_sessoes')")?->fetchColumn())->toBe('caixa_sessoes')
        ->and((string) $pdo->query("SELECT to_regclass('public.financeiro_saidas')")?->fetchColumn())->toBe('financeiro_saidas')
        ->and((int) $pdo->query(<<<'SQL'
            SELECT count(*)
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name IN ('caixa_sessoes', 'financeiro_saidas')
              AND column_name = 'tenant_id'
        SQL)?->fetchColumn())->toBe(0);
});

it('garante uma única sessão aberta por unidade no próprio PostgreSQL', function () {
    $pdo = caixaSchemaControlConnection($this->caixaSchemaDatabase);
    $pdo->exec("INSERT INTO caixa_sessoes (unidade_id, valor_abertura) VALUES ('und-001', 0)");

    expect(fn () => $pdo->exec("INSERT INTO caixa_sessoes (unidade_id, valor_abertura) VALUES ('und-001', 0)"))
        ->toThrow(PDOException::class);

    expect($pdo->exec("INSERT INTO caixa_sessoes (unidade_id, valor_abertura) VALUES ('und-002', 0)"))
        ->toBe(1);
});

it('recusa valor de abertura negativo no banco', function () {
    $pdo = caixaSchemaControlConnection($this->caixaSchemaDatabase);

    expect(fn () => $pdo->exec("INSERT INTO caixa_sessoes (unidade_id, valor_abertura) VALUES ('und-001', -0.01)"))
        ->toThrow(PDOException::class);
});

it('atualiza updated_at no banco ao alterar sessão', function () {
    $pdo = caixaSchemaControlConnection($this->caixaSchemaDatabase);
    $sessionId = (int) $pdo->query(<<<'SQL'
        INSERT INTO caixa_sessoes (unidade_id, valor_abertura, updated_at)
        VALUES ('und-001', 0, '2000-01-01 00:00:00+00')
        RETURNING id
    SQL)?->fetchColumn();

    $pdo->exec("UPDATE caixa_sessoes SET observacoes = 'Atualizada' WHERE id = {$sessionId}");

    expect((bool) $pdo->query("SELECT updated_at > '2000-01-01 00:00:00+00'::timestamptz FROM caixa_sessoes WHERE id = {$sessionId}")?->fetchColumn())
        ->toBeTrue();
});

it('impede movimento explicitamente vinculado a sessão fechada', function () {
    $pdo = caixaSchemaControlConnection($this->caixaSchemaDatabase);
    $sessionId = (int) $pdo->query(<<<'SQL'
        INSERT INTO caixa_sessoes (unidade_id, valor_abertura, status, fechada_em, valor_fechamento)
        VALUES ('und-001', 0, 'fechada', now(), 0)
        RETURNING id
    SQL)?->fetchColumn();

    $atendimentoId = (int) $pdo->query(<<<'SQL'
        INSERT INTO atendimentos (paciente_nome, paciente_cpf, unidade_id)
        VALUES ('Paciente Schema Caixa', '', 'und-001')
        RETURNING id
    SQL)?->fetchColumn();

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor, caixa_sessao_id)
        VALUES (?, 'Dinheiro', 10, ?)
    SQL);

    expect(fn () => $statement->execute([$atendimentoId, $sessionId]))
        ->toThrow(PDOException::class, 'caixa_fechado');
});

it('não permite delete físico de saída financeira', function () {
    $pdo = caixaSchemaControlConnection($this->caixaSchemaDatabase);
    $saidaId = (int) $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas
            (protocolo, descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('SAI-SCHEMA-001', 'Despesa', 10, 'Outros', 'Fornecedor')
        RETURNING id
    SQL)?->fetchColumn();

    expect(fn () => $pdo->exec("DELETE FROM financeiro_saidas WHERE id = {$saidaId}"))
        ->toThrow(PDOException::class, 'use estorno');
});

it('não permite delete físico de sessão de caixa', function () {
    $pdo = caixaSchemaControlConnection($this->caixaSchemaDatabase);
    $sessionId = (int) $pdo->query(<<<'SQL'
        INSERT INTO caixa_sessoes (unidade_id, valor_abertura)
        VALUES ('und-001', 0)
        RETURNING id
    SQL)?->fetchColumn();

    expect(fn () => $pdo->exec("DELETE FROM caixa_sessoes WHERE id = {$sessionId}"))
        ->toThrow(PDOException::class, 'sessão de caixa não pode ser excluída');
});

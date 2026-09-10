<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->saidasSchemaDatabase = 'sislac_t_saidas_schema_'.Str::lower(Str::random(7));
    saidasSchemaControlConnection()->exec('CREATE DATABASE "'.$this->saidasSchemaDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Schema Saídas',
        'code' => 'saidas-schema-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'database_name' => $this->saidasSchemaDatabase,
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
    saidasSchemaControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->saidasSchemaDatabase.'" WITH (FORCE)');
});

function saidasSchemaControlConnection(?string $database = null): PDO
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

function insertSchemaSaida(PDO $pdo, array $overrides = []): array
{
    $defaults = [
        'protocolo' => 'CLIENTE-'.Str::upper(Str::random(8)),
        'data' => '2026-09-10 12:00:00+00',
        'descricao' => 'Despesa operacional',
        'valor' => '100.00',
        'tipo_despesa' => 'Outros',
        'destino_pagamento' => 'Fornecedor',
        'status' => 'aberta',
        'foi_pago' => false,
        'data_pagamento' => null,
        'forma_pagamento' => null,
    ];
    $row = array_merge($defaults, $overrides);

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO financeiro_saidas
            (protocolo, data, descricao, valor, tipo_despesa, destino_pagamento, status, foi_pago, data_pagamento, forma_pagamento)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id, protocolo, data::text, descricao, valor::text, tipo_despesa, destino_pagamento,
                  status, foi_pago, data_pagamento::text, forma_pagamento, assinatura_protocolo, caixa_sessao_id
    SQL);
    $statement->execute([
        $row['protocolo'],
        $row['data'],
        $row['descricao'],
        $row['valor'],
        $row['tipo_despesa'],
        $row['destino_pagamento'],
        $row['status'],
        $row['foi_pago'],
        $row['data_pagamento'],
        $row['forma_pagamento'],
    ]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}

it('gera protocolo oficial SAI no PostgreSQL usando o ano da saída', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo, ['data' => '2025-12-31 12:00:00+00']);

    expect($row['protocolo'] ?? null)->toMatch('/^SAI-2025-\d{7}$/');
});

it('gera protocolos sequenciais únicos por ano', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $first = insertSchemaSaida($pdo, ['data' => '2026-01-01 12:00:00+00']);
    $second = insertSchemaSaida($pdo, ['data' => '2026-02-01 12:00:00+00']);
    $nextYear = insertSchemaSaida($pdo, ['data' => '2027-01-01 12:00:00+00']);

    expect($first['protocolo'] ?? null)->toBe('SAI-2026-0000001')
        ->and($second['protocolo'] ?? null)->toBe('SAI-2026-0000002')
        ->and($nextYear['protocolo'] ?? null)->toBe('SAI-2027-0000001');
});

it('recusa valor zero e negativo no próprio banco', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);

    expect(fn () => insertSchemaSaida($pdo, ['valor' => '0.00']))
        ->toThrow(PDOException::class);

    expect(fn () => insertSchemaSaida($pdo, ['valor' => '-0.01']))
        ->toThrow(PDOException::class);
});

it('torna protocolo imutável depois da criação', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo);
    $statement = $pdo->prepare('UPDATE financeiro_saidas SET protocolo = ? WHERE id = ?');

    expect(fn () => $statement->execute(['SAI-2099-9999999', (int) $row['id']]))
        ->toThrow(PDOException::class, 'protocolo da saída é imutável');
});

it('torna assinatura não nula imutável', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo);
    $pdo->exec("UPDATE financeiro_saidas SET assinatura_protocolo = 'assinatura-original' WHERE id = ".(int) $row['id']);
    $statement = $pdo->prepare('UPDATE financeiro_saidas SET assinatura_protocolo = ? WHERE id = ?');

    expect(fn () => $statement->execute(['assinatura-alterada', (int) $row['id']]))
        ->toThrow(PDOException::class, 'assinatura da saída é imutável');
});

it('normaliza status paga como fonte de verdade', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo, [
        'status' => 'paga',
        'foi_pago' => false,
        'data_pagamento' => null,
        'forma_pagamento' => 'Crédito',
    ]);

    expect($row['status'] ?? null)->toBe('paga')
        ->and($row['foi_pago'] ?? null)->toBeTrue()
        ->and($row['data_pagamento'] ?? null)->toBe(now()->toDateString());
});

it('normaliza status aberta removendo sinalização de pagamento', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo, [
        'status' => 'aberta',
        'foi_pago' => true,
        'data_pagamento' => '2026-09-10',
    ]);

    expect($row['status'] ?? null)->toBe('aberta')
        ->and($row['foi_pago'] ?? null)->toBeFalse()
        ->and($row['data_pagamento'] ?? null)->toBeNull();
});

it('permite apenas a transição aberta para paga como atualização operacional', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo);
    $pdo->exec("UPDATE financeiro_saidas SET status = 'paga', forma_pagamento = 'PIX' WHERE id = ".(int) $row['id']);

    $persisted = $pdo->query('SELECT status, foi_pago, data_pagamento::text, forma_pagamento FROM financeiro_saidas WHERE id = '.(int) $row['id'])?->fetch(PDO::FETCH_ASSOC);

    expect($persisted)->toMatchArray([
        'status' => 'paga',
        'foi_pago' => true,
        'data_pagamento' => now()->toDateString(),
        'forma_pagamento' => 'PIX',
    ]);
});

it('impede alteração dos campos de negócio depois de paga', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo, [
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
    ]);
    $statement = $pdo->prepare('UPDATE financeiro_saidas SET valor = ?, descricao = ? WHERE id = ?');

    expect(fn () => $statement->execute(['999.00', 'Despesa adulterada', (int) $row['id']]))
        ->toThrow(PDOException::class, 'saída financeira terminal é imutável');
});

it('não permite cancelamento direto sem estorno correspondente', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo);
    $statement = $pdo->prepare("UPDATE financeiro_saidas SET status = 'cancelada' WHERE id = ?");

    expect(fn () => $statement->execute([(int) $row['id']]))
        ->toThrow(PDOException::class, 'saída só pode ser cancelada por estorno');
});

it('permite cancelamento formal com estorno na mesma transação e mantém estado terminal', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo, [
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
        'data_pagamento' => '2026-09-10',
    ]);

    $pdo->beginTransaction();
    $estorno = $pdo->prepare(<<<'SQL'
        INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor)
        VALUES ('saida', ?, 'Cancelamento formal', ?)
    SQL);
    $estorno->execute([(int) $row['id'], (string) $row['valor']]);
    $update = $pdo->prepare("UPDATE financeiro_saidas SET status = 'cancelada' WHERE id = ?");
    $update->execute([(int) $row['id']]);
    $pdo->commit();

    $persisted = $pdo->query('SELECT status, foi_pago, data_pagamento::text, valor::text, forma_pagamento FROM financeiro_saidas WHERE id = '.(int) $row['id'])?->fetch(PDO::FETCH_ASSOC);
    expect($persisted)->toMatchArray([
        'status' => 'cancelada',
        'foi_pago' => false,
        'data_pagamento' => '2026-09-10',
        'valor' => '100.00',
        'forma_pagamento' => 'Crédito',
    ]);

    $statement = $pdo->prepare("UPDATE financeiro_saidas SET status = 'aberta' WHERE id = ?");
    expect(fn () => $statement->execute([(int) $row['id']]))
        ->toThrow(PDOException::class, 'saída financeira terminal é imutável');
});

it('mantém delete físico bloqueado', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $row = insertSchemaSaida($pdo);

    expect(fn () => $pdo->exec('DELETE FROM financeiro_saidas WHERE id = '.(int) $row['id']))
        ->toThrow(PDOException::class, 'use estorno');
});

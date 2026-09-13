<?php

use App\Models\Laboratory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->saidasSchemaDatabase = 'sislac_t_saidas_schema_'.Str::lower(Str::random(7));
    saidasSchemaControlConnection()->exec('CREATE DATABASE "'.$this->saidasSchemaDatabase.'"');

    $laboratoryId = (string) Str::uuid();
    $now = now();

    createTestLaboratory([
        'id' => $laboratoryId,
        'name' => 'Laboratório Schema Saídas',
        'code' => 'saidas-schema-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'database_name' => $this->saidasSchemaDatabase,
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

    disconnectTestLaboratory();
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
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

it('gera protocolo SAI server-side a partir do ano da data', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);

    $protocol = $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas
            (data, descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('2026-09-10 10:00:00+00', 'Material de limpeza', 35.50, 'Insumos', 'Fornecedor')
        RETURNING protocolo
    SQL)?->fetchColumn();

    expect($protocol)->toMatch('/^SAI-2026-\d{7}$/');
});

it('gera protocolos sequenciais únicos no mesmo ano', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);

    $first = $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas (data, descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('2026-01-01 10:00:00+00', 'Despesa A', 10, 'Outros', 'Fornecedor')
        RETURNING protocolo
    SQL)?->fetchColumn();

    $second = $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas (data, descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('2026-01-02 10:00:00+00', 'Despesa B', 20, 'Outros', 'Fornecedor')
        RETURNING protocolo
    SQL)?->fetchColumn();

    expect($first)->not->toBe($second)
        ->and($first)->toBe('SAI-2026-0000001')
        ->and($second)->toBe('SAI-2026-0000002');
});

it('não permite alterar protocolo depois da criação', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $id = (int) $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas (descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('Despesa', 10, 'Outros', 'Fornecedor')
        RETURNING id
    SQL)?->fetchColumn();

    expect(fn () => $pdo->exec("UPDATE financeiro_saidas SET protocolo = 'SAI-2099-9999999' WHERE id = {$id}"))
        ->toThrow(PDOException::class, 'protocolo');
});

it('exige valor estritamente positivo no banco', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);

    expect(fn () => $pdo->exec(<<<'SQL'
        INSERT INTO financeiro_saidas (descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('Despesa zero', 0, 'Outros', 'Fornecedor')
    SQL))->toThrow(PDOException::class);

    expect(fn () => $pdo->exec(<<<'SQL'
        INSERT INTO financeiro_saidas (descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('Despesa negativa', -0.01, 'Outros', 'Fornecedor')
    SQL))->toThrow(PDOException::class);
});

it('normaliza saída aberta como não paga e sem data de pagamento', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);

    $row = $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas
            (descricao, valor, tipo_despesa, destino_pagamento, status, foi_pago, data_pagamento)
        VALUES ('Despesa aberta', 10, 'Outros', 'Fornecedor', 'aberta', true, '2026-09-10')
        RETURNING status, foi_pago, data_pagamento
    SQL)?->fetch(PDO::FETCH_ASSOC);

    expect($row['status'])->toBe('aberta')
        ->and((bool) $row['foi_pago'])->toBeFalse()
        ->and($row['data_pagamento'])->toBeNull();
});

it('normaliza saída paga como paga e preenche data de pagamento', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);

    $row = $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas
            (descricao, valor, tipo_despesa, destino_pagamento, status)
        VALUES ('Despesa paga', 10, 'Outros', 'Fornecedor', 'paga')
        RETURNING status, foi_pago, data_pagamento
    SQL)?->fetch(PDO::FETCH_ASSOC);

    expect($row['status'])->toBe('paga')
        ->and((bool) $row['foi_pago'])->toBeTrue()
        ->and($row['data_pagamento'])->not->toBeNull();
});

it('recusa criação direta de saída cancelada', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);

    expect(fn () => $pdo->exec(<<<'SQL'
        INSERT INTO financeiro_saidas
            (descricao, valor, tipo_despesa, destino_pagamento, status)
        VALUES ('Cancelada direta', 10, 'Outros', 'Fornecedor', 'cancelada')
    SQL))->toThrow(PDOException::class, 'estorno');
});

it('recusa cancelamento direto sem estorno canônico', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $id = (int) $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas (descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('Despesa aberta', 10, 'Outros', 'Fornecedor')
        RETURNING id
    SQL)?->fetchColumn();

    expect(fn () => $pdo->exec("UPDATE financeiro_saidas SET status = 'cancelada' WHERE id = {$id}"))
        ->toThrow(PDOException::class, 'estorno');
});

it('congela campos de negócio depois que a saída fica paga', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $id = (int) $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas
            (descricao, valor, tipo_despesa, destino_pagamento, status, forma_pagamento)
        VALUES ('Despesa paga', 10, 'Outros', 'Fornecedor', 'paga', 'PIX')
        RETURNING id
    SQL)?->fetchColumn();

    expect(fn () => $pdo->exec("UPDATE financeiro_saidas SET valor = 99 WHERE id = {$id}"))
        ->toThrow(PDOException::class, 'imutável');
});

it('mantém saída cancelada terminal depois de estorno formal', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $id = (int) $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas (descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('Despesa aberta', 10, 'Outros', 'Fornecedor')
        RETURNING id
    SQL)?->fetchColumn();

    $pdo->exec(<<<SQL
        INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor)
        VALUES ('saida', {$id}, 'Cancelamento de teste', 10)
    SQL);
    $pdo->exec("UPDATE financeiro_saidas SET status = 'cancelada' WHERE id = {$id}");

    expect(fn () => $pdo->exec("UPDATE financeiro_saidas SET status = 'aberta' WHERE id = {$id}"))
        ->toThrow(PDOException::class, 'terminal');
});

it('continua bloqueando delete físico de saída', function () {
    $pdo = saidasSchemaControlConnection($this->saidasSchemaDatabase);
    $id = (int) $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas (descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('Despesa', 10, 'Outros', 'Fornecedor')
        RETURNING id
    SQL)?->fetchColumn();

    expect(fn () => $pdo->exec("DELETE FROM financeiro_saidas WHERE id = {$id}"))
        ->toThrow(PDOException::class, 'use estorno');
});

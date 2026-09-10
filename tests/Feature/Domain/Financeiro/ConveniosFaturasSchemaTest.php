<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conveniosSchemaDatabase = 'sislac_t_convenios_schema_'.Str::lower(Str::random(7));
    conveniosSchemaControlConnection()->exec('CREATE DATABASE "'.$this->conveniosSchemaDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Schema Convênios',
        'code' => 'convenios-schema-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'database_name' => $this->conveniosSchemaDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    tenancy()->initialize(Tenant::query()->findOrFail($tenantId));

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
    conveniosSchemaControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->conveniosSchemaDatabase.'" WITH (FORCE)');
});

function conveniosSchemaControlConnection(?string $database = null): PDO
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

function convenioSchemaSeedConvenio(PDO $pdo, string $nome = 'Convênio Teste'): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO convenios (nome, registro_ans, tipo, tabela)
        VALUES (?, '', 'Saúde', 'Própria')
        RETURNING id
    SQL);
    $statement->execute([$nome]);

    return (int) $statement->fetchColumn();
}

/** @return array{atendimento_id:int, exame_id:int} */
function convenioSchemaSeedExame(
    PDO $pdo,
    int $convenioId,
    string $status = 'finalizado',
    string $destino = 'convenio',
    string $data = '2026-09-10 12:00:00+00',
    string $valor = '120.00',
): array {
    $atendimento = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimentos (paciente_nome, paciente_cpf, data, convenio_id, convenio_nome)
        VALUES ('Paciente Convênio', '', ?::timestamptz, ?, 'Convênio Teste')
        RETURNING id
    SQL);
    $atendimento->execute([$data, $convenioId]);
    $atendimentoId = (int) $atendimento->fetchColumn();

    $exame = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_exames
            (atendimento_id, nome_exame, status, valor, cobranca_destino, convenio_cobranca_id)
        VALUES (?, 'Hemograma Convênio', ?, ?, ?, ?)
        RETURNING id
    SQL);
    $exame->execute([$atendimentoId, $status, $valor, $destino, $convenioId]);

    return [
        'atendimento_id' => $atendimentoId,
        'exame_id' => (int) $exame->fetchColumn(),
    ];
}

function convenioSchemaCreateFatura(
    PDO $pdo,
    int $convenioId,
    string $inicio = '2026-09-01',
    string $fim = '2026-09-30',
    string $desconto = '0.00',
): int {
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO convenio_faturas (convenio_id, periodo_inicio, periodo_fim, desconto)
        VALUES (?, ?::date, ?::date, ?)
        RETURNING id
    SQL);
    $statement->execute([$convenioId, $inicio, $fim, $desconto]);

    return (int) $statement->fetchColumn();
}

it('cria tabelas canônicas sem tenant_id e semeia Particular', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);

    foreach (['convenios', 'convenio_faturas', 'convenio_fatura_itens'] as $table) {
        expect((string) $pdo->query("SELECT to_regclass('public.{$table}')")?->fetchColumn())->toBe($table);
    }

    expect((int) $pdo->query(<<<'SQL'
        SELECT count(*)
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name IN ('convenios', 'convenio_faturas', 'convenio_fatura_itens')
          AND column_name = 'tenant_id'
    SQL)?->fetchColumn())->toBe(0);

    $particular = $pdo->query('SELECT id, nome, ativo FROM convenios WHERE id = 0')?->fetch(PDO::FETCH_ASSOC);

    expect($particular)->toBeArray()
        ->and((int) ($particular['id'] ?? -1))->toBe(0)
        ->and($particular['nome'] ?? null)->toBe('Particular')
        ->and((bool) ($particular['ativo'] ?? false))->toBeTrue();
});

it('protege o convênio Particular contra renomear desativar e excluir', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);

    expect(fn () => $pdo->exec('UPDATE convenios SET nome = \'Outro\' WHERE id = 0'))
        ->toThrow(PDOException::class, 'Particular');
    expect(fn () => $pdo->exec('UPDATE convenios SET ativo = false WHERE id = 0'))
        ->toThrow(PDOException::class, 'Particular');
    expect(fn () => $pdo->exec('DELETE FROM convenios WHERE id = 0'))
        ->toThrow(PDOException::class, 'Particular');
});

it('gera código FAT sequencial e o torna imutável', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);
    $convenioId = convenioSchemaSeedConvenio($pdo);

    $fatura1 = convenioSchemaCreateFatura($pdo, $convenioId);
    $fatura2 = convenioSchemaCreateFatura($pdo, $convenioId);

    $codes = $pdo->query("SELECT codigo FROM convenio_faturas WHERE id IN ({$fatura1}, {$fatura2}) ORDER BY id")?->fetchAll(PDO::FETCH_COLUMN);

    expect($codes)->toBe([
        'FAT-2026-0000001',
        'FAT-2026-0000002',
    ]);

    expect(fn () => $pdo->exec("UPDATE convenio_faturas SET codigo = 'FAT-2026-9999999' WHERE id = {$fatura1}"))
        ->toThrow(PDOException::class, 'código da fatura é imutável');
});

it('rejeita fatura Particular período invertido e valores monetários negativos', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);
    $convenioId = convenioSchemaSeedConvenio($pdo);

    expect(fn () => convenioSchemaCreateFatura($pdo, 0))->toThrow(PDOException::class);
    expect(fn () => convenioSchemaCreateFatura($pdo, $convenioId, '2026-10-01', '2026-09-30'))
        ->toThrow(PDOException::class);
    expect(fn () => convenioSchemaCreateFatura($pdo, $convenioId, desconto: '-0.01'))
        ->toThrow(PDOException::class);
});

it('faz snapshot do valor do exame e recalcula subtotal e total no banco', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);
    $convenioId = convenioSchemaSeedConvenio($pdo);
    $exame = convenioSchemaSeedExame($pdo, $convenioId, valor: '120.00');
    $faturaId = convenioSchemaCreateFatura($pdo, $convenioId, desconto: '10.00');

    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO convenio_fatura_itens (fatura_id, atendimento_exame_id, valor)
        VALUES (?, ?, 999.99)
        RETURNING valor
    SQL);
    $insert->execute([$faturaId, $exame['exame_id']]);

    expect((string) $insert->fetchColumn())->toBe('120.00');

    $fatura = $pdo->query("SELECT subtotal, total FROM convenio_faturas WHERE id = {$faturaId}")?->fetch(PDO::FETCH_ASSOC);

    expect($fatura['subtotal'] ?? null)->toBe('120.00')
        ->and($fatura['total'] ?? null)->toBe('110.00');
});

it('rejeita item se exame não estiver finalizado ou não pertencer à cobrança do convênio', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);
    $convenioId = convenioSchemaSeedConvenio($pdo);
    $outroConvenioId = convenioSchemaSeedConvenio($pdo, 'Outro Convênio');

    $pendente = convenioSchemaSeedExame($pdo, $convenioId, status: 'pendente');
    $paciente = convenioSchemaSeedExame($pdo, $convenioId, destino: 'paciente');
    $outro = convenioSchemaSeedExame($pdo, $outroConvenioId);
    $faturaId = convenioSchemaCreateFatura($pdo, $convenioId);

    $insert = $pdo->prepare('INSERT INTO convenio_fatura_itens (fatura_id, atendimento_exame_id, valor) VALUES (?, ?, 0)');

    expect(fn () => $insert->execute([$faturaId, $pendente['exame_id']]))
        ->toThrow(PDOException::class, 'exame não elegível');
    expect(fn () => $insert->execute([$faturaId, $paciente['exame_id']]))
        ->toThrow(PDOException::class, 'exame não elegível');
    expect(fn () => $insert->execute([$faturaId, $outro['exame_id']]))
        ->toThrow(PDOException::class, 'exame não elegível');
});

it('rejeita dois vínculos ativos para o mesmo exame', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);
    $convenioId = convenioSchemaSeedConvenio($pdo);
    $exame = convenioSchemaSeedExame($pdo, $convenioId);
    $fatura1 = convenioSchemaCreateFatura($pdo, $convenioId);
    $fatura2 = convenioSchemaCreateFatura($pdo, $convenioId);

    $insert = $pdo->prepare('INSERT INTO convenio_fatura_itens (fatura_id, atendimento_exame_id, valor) VALUES (?, ?, 0)');
    expect($insert->execute([$fatura1, $exame['exame_id']]))->toBeTrue();

    expect(fn () => $insert->execute([$fatura2, $exame['exame_id']]))
        ->toThrow(PDOException::class, 'exame já pertence a fatura ativa');
});

it('permite refaturar exame preservado em fatura cancelada', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);
    $convenioId = convenioSchemaSeedConvenio($pdo);
    $exame = convenioSchemaSeedExame($pdo, $convenioId);
    $fatura1 = convenioSchemaCreateFatura($pdo, $convenioId);

    $insert = $pdo->prepare('INSERT INTO convenio_fatura_itens (fatura_id, atendimento_exame_id, valor) VALUES (?, ?, 0)');
    $insert->execute([$fatura1, $exame['exame_id']]);

    $pdo->exec("UPDATE convenio_faturas SET status = 'cancelada', motivo_cancelamento = 'Cancelamento de teste', cancelada_em = now() WHERE id = {$fatura1}");

    $fatura2 = convenioSchemaCreateFatura($pdo, $convenioId);

    expect($insert->execute([$fatura2, $exame['exame_id']]))->toBeTrue()
        ->and((int) $pdo->query("SELECT count(*) FROM convenio_fatura_itens WHERE atendimento_exame_id = {$exame['exame_id']}")?->fetchColumn())->toBe(2);
});

it('torna item de fatura imutável e bloqueia delete físico', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);
    $convenioId = convenioSchemaSeedConvenio($pdo);
    $exame = convenioSchemaSeedExame($pdo, $convenioId);
    $faturaId = convenioSchemaCreateFatura($pdo, $convenioId);

    $itemId = (int) $pdo->query(<<<SQL
        INSERT INTO convenio_fatura_itens (fatura_id, atendimento_exame_id, valor)
        VALUES ({$faturaId}, {$exame['exame_id']}, 0)
        RETURNING id
    SQL)?->fetchColumn();

    expect(fn () => $pdo->exec("UPDATE convenio_fatura_itens SET valor = 1 WHERE id = {$itemId}"))
        ->toThrow(PDOException::class, 'item de fatura é imutável');
    expect(fn () => $pdo->exec("DELETE FROM convenio_fatura_itens WHERE id = {$itemId}"))
        ->toThrow(PDOException::class, 'item de fatura não pode ser excluído');
});

it('protege faturas terminais e bloqueia delete físico', function () {
    $pdo = conveniosSchemaControlConnection($this->conveniosSchemaDatabase);
    $convenioId = convenioSchemaSeedConvenio($pdo);
    $faturaId = convenioSchemaCreateFatura($pdo, $convenioId);

    $pdo->exec("UPDATE convenio_faturas SET status = 'paga', forma_pagamento = 'Transferência', data_pagamento = '2026-09-10' WHERE id = {$faturaId}");

    expect(fn () => $pdo->exec("UPDATE convenio_faturas SET desconto = 1 WHERE id = {$faturaId}"))
        ->toThrow(PDOException::class, 'fatura terminal é imutável');
    expect(fn () => $pdo->exec("UPDATE convenio_faturas SET status = 'cancelada' WHERE id = {$faturaId}"))
        ->toThrow(PDOException::class, 'estorno');
    expect(fn () => $pdo->exec("DELETE FROM convenio_faturas WHERE id = {$faturaId}"))
        ->toThrow(PDOException::class, 'fatura não pode ser excluída');
});

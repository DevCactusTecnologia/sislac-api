<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentoInvariantDatabase = 'sislac_t_ati_'.Str::lower(Str::random(10));
    atendimentoInvariantControl()->exec('CREATE DATABASE "'.$this->atendimentoInvariantDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Invariantes',
        'code' => 'ati-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentoInvariantDatabase,
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
    atendimentoInvariantControl()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentoInvariantDatabase.'" WITH (FORCE)');
});

function atendimentoInvariantControl(?string $database = null): PDO
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

it('gera protocolo numérico de sete dígitos e impede alteração posterior', function () {
    $pdo = atendimentoInvariantControl($this->atendimentoInvariantDatabase);

    $pdo->exec("INSERT INTO atendimentos (paciente_nome, paciente_cpf) VALUES ('Paciente A', '')");
    $pdo->exec("INSERT INTO atendimentos (paciente_nome, paciente_cpf) VALUES ('Paciente B', '')");

    $protocolos = $pdo->query('SELECT protocolo FROM atendimentos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    expect($protocolos)->toBe(['0000001', '0000002']);

    expect(fn () => $pdo->exec("UPDATE atendimentos SET protocolo = '9999999' WHERE id = 1"))
        ->toThrow(PDOException::class);
});

it('impede duas linhas com a mesma chave de idempotência', function () {
    $pdo = atendimentoInvariantControl($this->atendimentoInvariantDatabase);
    $key = (string) Str::uuid();

    $statement = $pdo->prepare('INSERT INTO atendimentos (paciente_nome, paciente_cpf, idempotency_key) VALUES (?, ?, ?)');
    $statement->execute(['Paciente A', '', $key]);

    expect(fn () => $statement->execute(['Paciente B', '', $key]))
        ->toThrow(PDOException::class);
});

it('recalcula totais e estados clínico e financeiro a partir dos filhos', function () {
    $pdo = atendimentoInvariantControl($this->atendimentoInvariantDatabase);
    $pdo->exec("INSERT INTO atendimentos (paciente_nome, paciente_cpf) VALUES ('Paciente Estado', '')");
    $id = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO atendimento_exames (atendimento_id, nome_exame, status, valor_original, valor) VALUES ({$id}, 'Hemograma', 'pendente', 100, 90)");

    $row = $pdo->query("SELECT status_atendimento, status_pagamento, subtotal, desconto_total, acrescimo_total, total FROM atendimentos WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

    expect($row)->toMatchArray([
        'status_atendimento' => 'Pedido Realizado',
        'status_pagamento' => 'Pagamento pendente',
        'subtotal' => '100.00',
        'desconto_total' => '10.00',
        'acrescimo_total' => '0.00',
        'total' => '90.00',
    ]);

    $pdo->exec("INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor) VALUES ({$id}, 'Dinheiro', 40)");
    $pdo->exec("UPDATE atendimento_exames SET status = 'coletado' WHERE atendimento_id = {$id}");

    $row = $pdo->query("SELECT status_atendimento, status_pagamento FROM atendimentos WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

    expect($row)->toMatchArray([
        'status_atendimento' => 'Amostra Coletada',
        'status_pagamento' => 'Pagamento parcial',
    ]);

    $pdo->exec("UPDATE atendimento_exames SET status = 'finalizado' WHERE atendimento_id = {$id}");
    $pdo->exec("INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor) VALUES ({$id}, 'Pix', 50)");

    $row = $pdo->query("SELECT status_atendimento, status_pagamento FROM atendimentos WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

    expect($row)->toMatchArray([
        'status_atendimento' => 'Resultado Liberado',
        'status_pagamento' => 'Pagamento efetuado',
    ]);
});

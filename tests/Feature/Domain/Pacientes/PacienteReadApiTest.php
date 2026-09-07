<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->readDatabase = 'sislac_t_read_'.Str::lower(Str::random(10));
    pacienteReadControlConnection()->exec('CREATE DATABASE "'.$this->readDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Leitura',
        'code' => 'read-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->readDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->readTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->readTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->readUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->readUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'recepcionista',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->actingAs($this->readUser, 'web');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    pacienteReadControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->readDatabase.'" WITH (FORCE)');
});

function pacienteReadControlConnection(?string $database = null): PDO
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

function seedPacienteReadRows(string $database, int $count): void
{
    $pdo = pacienteReadControlConnection($database);
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO pacientes (nome, cpf, status, friendly_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)
    SQL);

    for ($index = 1; $index <= $count; $index++) {
        $timestamp = sprintf('2026-09-%02d 12:%02d:00+00', (($index - 1) % 7) + 1, $index % 60);
        $statement->execute([
            sprintf('Paciente %03d', $index),
            sprintf('%011d', 10000000000 + $index),
            $index % 3 === 0 ? 'Inativo' : 'Ativo',
            sprintf('PAC-%06d', $index),
            $timestamp,
            $timestamp,
        ]);
    }
}

it('pagina por cursor em blocos de 50 sem repetir registros', function () {
    seedPacienteReadRows($this->readDatabase, 55);

    $first = $this->getJson('/api/pacientes')
        ->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.counts.todos', 55)
        ->assertJsonPath('meta.counts.ativos', 37)
        ->assertJsonPath('meta.counts.inativos', 18);

    $cursor = $first->json('meta.nextCursor');
    expect($cursor)->toBeString()->not->toBe('');

    $firstIds = collect($first->json('data'))->pluck('id')->all();
    $second = $this->getJson('/api/pacientes?cursor='.urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonCount(5, 'data');

    $secondIds = collect($second->json('data'))->pluck('id')->all();

    expect(array_intersect($firstIds, $secondIds))->toBe([])
        ->and($second->json('meta.nextCursor'))->toBeNull();
});

it('rejeita cursor estruturalmente inválido com 422', function () {
    $this->getJson('/api/pacientes?cursor=nao-e-um-cursor')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
});

it('prova que o planner pode usar o índice composto da paginação por cursor', function () {
    seedPacienteReadRows($this->readDatabase, 200);
    $pdo = pacienteReadControlConnection($this->readDatabase);

    $pdo->exec('SET enable_seqscan = off');

    try {
        $plan = $pdo->query(<<<'SQL'
            EXPLAIN (FORMAT JSON)
            SELECT id, updated_at
            FROM pacientes
            ORDER BY updated_at DESC, id DESC
            LIMIT 51
        SQL)->fetchColumn();
    } finally {
        $pdo->exec('RESET enable_seqscan');
    }

    expect($plan)->toBeString()
        ->and($plan)->toContain('idx_pacientes_cursor');
});

it('filtra status sem alterar os contadores globais da busca', function () {
    $pdo = pacienteReadControlConnection($this->readDatabase);
    $pdo->exec("INSERT INTO pacientes (nome, cpf, status, friendly_id) VALUES ('Maria Ativa', '11111111111', 'Ativo', 'PAC-000101')");
    $pdo->exec("INSERT INTO pacientes (nome, cpf, status, friendly_id) VALUES ('Maria Inativa', '22222222222', 'Inativo', 'PAC-000102')");
    $pdo->exec("INSERT INTO pacientes (nome, cpf, status, friendly_id) VALUES ('João Outro', '33333333333', 'Ativo', 'PAC-000103')");

    $this->getJson('/api/pacientes?status=Ativo&q=Maria')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nome', 'Maria Ativa')
        ->assertJsonPath('meta.counts.todos', 2)
        ->assertJsonPath('meta.counts.ativos', 1)
        ->assertJsonPath('meta.counts.inativos', 1);
});

it('usa cpf parcial quando a busca contém pelo menos três dígitos', function () {
    $pdo = pacienteReadControlConnection($this->readDatabase);
    $pdo->exec("INSERT INTO pacientes (nome, cpf, friendly_id) VALUES ('Alvo CPF', '12345678901', 'PAC-000201')");
    $pdo->exec("INSERT INTO pacientes (nome, cpf, friendly_id) VALUES ('Outro CPF', '98765432100', 'PAC-000202')");

    $this->getJson('/api/pacientes?q=456')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nome', 'Alvo CPF');
});

it('retorna 404 para paciente inexistente', function () {
    $this->getJson('/api/pacientes/999999')->assertNotFound();
});

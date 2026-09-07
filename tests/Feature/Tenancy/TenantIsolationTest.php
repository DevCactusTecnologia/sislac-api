<?php

use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenantDatabases = [];
    $this->withHeader('Origin', 'https://sislac.com.br');
});

afterEach(function () {
    DB::purge('tenant');

    foreach ($this->tenantDatabases as $database) {
        postgresControlConnection()->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
    }
});

function postgresControlConnection(?string $database = null): PDO
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

function createTenantDatabase(string $marker): array
{
    $database = 'sislac_t_test_'.Str::lower(Str::random(12));

    expect($database)->toMatch('/\Asislac_t_[a-z0-9_]+\z/');

    postgresControlConnection()->exec('CREATE DATABASE "'.$database.'"');

    $pdo = postgresControlConnection($database);
    $pdo->exec('CREATE TABLE tenant_probe (marker text NOT NULL)');
    $statement = $pdo->prepare('INSERT INTO tenant_probe (marker) VALUES (?)');
    $statement->execute([$marker]);

    return [$database, $marker];
}

function createCentralTenantForIsolation(string $database, string $code): string
{
    $id = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $id,
        'name' => 'Laboratório '.$code,
        'code' => $code,
        'status' => 'active',
        'database_name' => $database,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function attachActiveMembership(User $user, string $tenantId): void
{
    $now = now();

    DB::connection('central')->table('memberships')->insert([
        'user_id' => $user->id,
        'tenant_id' => $tenantId,
        'role' => 'admin',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('seleciona automaticamente o único tenant e consulta somente o banco dele', function () {
    [$databaseA] = createTenantDatabase('TENANT-A');
    [$databaseB] = createTenantDatabase('TENANT-B');
    $this->tenantDatabases = [$databaseA, $databaseB];

    $tenantA = createCentralTenantForIsolation($databaseA, 'lab-a-'.Str::lower(Str::random(6)));
    createCentralTenantForIsolation($databaseB, 'lab-b-'.Str::lower(Str::random(6)));

    $user = User::factory()->create();
    attachActiveMembership($user, $tenantA);

    $this->actingAs($user, 'web')
        ->getJson('/api/tenant/context')
        ->assertOk()
        ->assertJsonPath('marker', 'TENANT-A');

    expect(config('database.default'))->toBe('central');
});

it('recusa X-Tenant sem vínculo antes de abrir o banco solicitado', function () {
    [$databaseA] = createTenantDatabase('TENANT-A');
    $this->tenantDatabases = [$databaseA];

    $tenantA = createCentralTenantForIsolation($databaseA, 'lab-a-'.Str::lower(Str::random(6)));
    $tenantB = createCentralTenantForIsolation('sislac_t_missing_'.Str::lower(Str::random(6)), 'lab-b-'.Str::lower(Str::random(6)));

    $user = User::factory()->create();
    attachActiveMembership($user, $tenantA);

    $this->actingAs($user, 'web')
        ->withHeader('X-Tenant', $tenantB)
        ->getJson('/api/tenant/context')
        ->assertForbidden();

    expect(config('database.default'))->toBe('central');
});

it('exige X-Tenant quando o usuário possui mais de um vínculo ativo', function () {
    [$databaseA] = createTenantDatabase('TENANT-A');
    [$databaseB] = createTenantDatabase('TENANT-B');
    $this->tenantDatabases = [$databaseA, $databaseB];

    $tenantA = createCentralTenantForIsolation($databaseA, 'lab-a-'.Str::lower(Str::random(6)));
    $tenantB = createCentralTenantForIsolation($databaseB, 'lab-b-'.Str::lower(Str::random(6)));

    $user = User::factory()->create();
    attachActiveMembership($user, $tenantA);
    attachActiveMembership($user, $tenantB);

    $this->actingAs($user, 'web')
        ->getJson('/api/tenant/context')
        ->assertStatus(409);

    $this->withHeader('X-Tenant', $tenantB)
        ->getJson('/api/tenant/context')
        ->assertOk()
        ->assertJsonPath('marker', 'TENANT-B');

    expect(config('database.default'))->toBe('central');
});

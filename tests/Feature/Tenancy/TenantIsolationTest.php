<?php

use App\Http\Middleware\EnsureTenantContext;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenantDatabases = [];
    $this->withHeader('Origin', 'https://sislac.com.br');

    Route::middleware(['api', 'auth:sanctum', EnsureTenantContext::class])
        ->get('/_test/tenant-probe', fn () => response()->json([
            'marker' => DB::table('tenant_probe')->value('marker'),
        ]));

    Route::middleware(['api', 'auth:sanctum', EnsureTenantContext::class])
        ->get('/_test/tenant-failure', fn () => throw new RuntimeException('falha sintética'));
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

function createTenantDatabase(string $marker): string
{
    $database = 'sislac_t_test_'.Str::lower(Str::random(12));

    expect($database)->toMatch('/\Asislac_t_[a-z0-9_]+\z/');

    postgresControlConnection()->exec('CREATE DATABASE "'.$database.'"');

    $pdo = postgresControlConnection($database);
    $pdo->exec('CREATE TABLE tenant_probe (marker text NOT NULL)');
    $statement = $pdo->prepare('INSERT INTO tenant_probe (marker) VALUES (?)');
    $statement->execute([$marker]);

    return $database;
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
    $databaseA = createTenantDatabase('TENANT-A');
    $databaseB = createTenantDatabase('TENANT-B');
    $this->tenantDatabases = [$databaseA, $databaseB];

    $tenantA = createCentralTenantForIsolation($databaseA, 'lab-a-'.Str::lower(Str::random(6)));
    createCentralTenantForIsolation($databaseB, 'lab-b-'.Str::lower(Str::random(6)));

    $user = User::factory()->create();
    attachActiveMembership($user, $tenantA);

    $this->actingAs($user, 'web')
        ->getJson('/_test/tenant-probe')
        ->assertOk()
        ->assertJsonPath('marker', 'TENANT-A');

    expect(config('database.default'))->toBe('central');
});

it('recusa X-Tenant sem vínculo antes de abrir o banco solicitado', function () {
    $databaseA = createTenantDatabase('TENANT-A');
    $this->tenantDatabases = [$databaseA];

    $tenantA = createCentralTenantForIsolation($databaseA, 'lab-a-'.Str::lower(Str::random(6)));
    $tenantB = createCentralTenantForIsolation('sislac_t_missing_'.Str::lower(Str::random(6)), 'lab-b-'.Str::lower(Str::random(6)));

    $user = User::factory()->create();
    attachActiveMembership($user, $tenantA);

    $this->actingAs($user, 'web')
        ->withHeader('X-Tenant', $tenantB)
        ->getJson('/_test/tenant-probe')
        ->assertForbidden();

    expect(config('database.default'))->toBe('central');
});

it('exige X-Tenant quando o usuário possui mais de um vínculo ativo', function () {
    $databaseA = createTenantDatabase('TENANT-A');
    $databaseB = createTenantDatabase('TENANT-B');
    $this->tenantDatabases = [$databaseA, $databaseB];

    $tenantA = createCentralTenantForIsolation($databaseA, 'lab-a-'.Str::lower(Str::random(6)));
    $tenantB = createCentralTenantForIsolation($databaseB, 'lab-b-'.Str::lower(Str::random(6)));

    $user = User::factory()->create();
    attachActiveMembership($user, $tenantA);
    attachActiveMembership($user, $tenantB);

    $this->actingAs($user, 'web')
        ->getJson('/_test/tenant-probe')
        ->assertStatus(409);

    $this->withHeader('X-Tenant', $tenantB)
        ->getJson('/_test/tenant-probe')
        ->assertOk()
        ->assertJsonPath('marker', 'TENANT-B');

    expect(config('database.default'))->toBe('central');
});

it('restaura o banco central mesmo quando a requisição tenant lança exceção', function () {
    $databaseA = createTenantDatabase('TENANT-A');
    $this->tenantDatabases = [$databaseA];

    $tenantA = createCentralTenantForIsolation($databaseA, 'lab-a-'.Str::lower(Str::random(6)));
    $user = User::factory()->create();
    attachActiveMembership($user, $tenantA);

    $this->withoutExceptionHandling();

    try {
        $this->actingAs($user, 'web')->get('/_test/tenant-failure');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('falha sintética');
    } finally {
        $this->withExceptionHandling();
    }

    expect(tenancy()->initialized)->toBeFalse()
        ->and(config('database.default'))->toBe('central');

    $this->getJson('/_test/tenant-probe')
        ->assertOk()
        ->assertJsonPath('marker', 'TENANT-A');
});

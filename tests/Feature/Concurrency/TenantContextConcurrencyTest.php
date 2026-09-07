<?php

use App\Http\Middleware\EnsureTenantContext;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->concurrencyDatabases = [];
    $this->withHeader('Origin', 'https://sislac.com.br');

    Route::middleware(['api', 'auth:sanctum', EnsureTenantContext::class])
        ->get('/_test/concurrency-tenant-probe', fn () => response()->json([
            'marker' => DB::table('tenant_probe')->value('marker'),
        ]));
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');

    foreach ($this->concurrencyDatabases as $database) {
        concurrencyControlConnection()->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
    }
});

function concurrencyControlConnection(?string $database = null): PDO
{
    $config = config('database.connections.central');

    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $database ?? 'postgres',
        ),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function createConcurrencyTenantDatabase(string $marker): string
{
    $database = 'sislac_t_conc_'.Str::lower(Str::random(12));
    concurrencyControlConnection()->exec('CREATE DATABASE "'.$database.'"');

    $pdo = concurrencyControlConnection($database);
    $pdo->exec('CREATE TABLE tenant_probe (marker text NOT NULL)');
    $statement = $pdo->prepare('INSERT INTO tenant_probe (marker) VALUES (?)');
    $statement->execute([$marker]);

    return $database;
}

function createConcurrencyTenant(string $database, string $suffix): string
{
    $id = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $id,
        'name' => 'Laboratório '.$suffix,
        'code' => 'conc-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $database,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('alterna A B A B sem vazar conexão nem contexto tenant', function () {
    $databaseA = createConcurrencyTenantDatabase('A');
    $databaseB = createConcurrencyTenantDatabase('B');
    $this->concurrencyDatabases = [$databaseA, $databaseB];

    $tenantA = createConcurrencyTenant($databaseA, 'A');
    $tenantB = createConcurrencyTenant($databaseB, 'B');
    $user = User::factory()->create();
    $now = now();

    DB::connection('central')->table('memberships')->insert([
        [
            'user_id' => $user->id,
            'tenant_id' => $tenantA,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'user_id' => $user->id,
            'tenant_id' => $tenantB,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $this->actingAs($user, 'web');

    foreach ([[$tenantA, 'A'], [$tenantB, 'B'], [$tenantA, 'A'], [$tenantB, 'B']] as [$tenantId, $marker]) {
        $this->withHeader('X-Tenant', $tenantId)
            ->getJson('/_test/concurrency-tenant-probe')
            ->assertOk()
            ->assertJsonPath('marker', $marker);

        expect(tenancy()->initialized)->toBeFalse()
            ->and(config('database.default'))->toBe('central');
    }
});

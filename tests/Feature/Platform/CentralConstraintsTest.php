<?php

use App\Platform\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createCentralTenant(array $overrides = []): array
{
    $id = (string) Str::uuid();

    return array_merge([
        'id' => $id,
        'name' => "Laboratório {$id}",
        'code' => "lab-{$id}",
        'status' => 'active',
        'database_name' => 'sislac_t_'.str_replace('-', '', $id),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createCentralMembership(string $userId, string $tenantId, array $overrides = []): array
{
    return array_merge([
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'role' => 'admin',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('impede vínculo duplicado entre o mesmo usuário e laboratório', function () {
    $user = User::factory()->create();
    $tenant = createCentralTenant();

    DB::connection('central')->table('tenants')->insert($tenant);
    DB::connection('central')->table('memberships')->insert(
        createCentralMembership((string) $user->id, $tenant['id']),
    );

    expect(fn () => DB::connection('central')->table('memberships')->insert(
        createCentralMembership((string) $user->id, $tenant['id']),
    ))->toThrow(QueryException::class);
});

it('impede membership para usuário inexistente', function () {
    $tenant = createCentralTenant();
    DB::connection('central')->table('tenants')->insert($tenant);

    expect(fn () => DB::connection('central')->table('memberships')->insert(
        createCentralMembership((string) Str::uuid(), $tenant['id']),
    ))->toThrow(QueryException::class);
});

it('impede membership para laboratório inexistente', function () {
    $user = User::factory()->create();

    expect(fn () => DB::connection('central')->table('memberships')->insert(
        createCentralMembership((string) $user->id, (string) Str::uuid()),
    ))->toThrow(QueryException::class);
});

it('impede código duplicado de laboratório', function () {
    $first = createCentralTenant(['code' => 'codigo-unico']);
    $second = createCentralTenant(['code' => 'codigo-unico']);

    DB::connection('central')->table('tenants')->insert($first);

    expect(fn () => DB::connection('central')->table('tenants')->insert($second))
        ->toThrow(QueryException::class);
});

it('impede nome de banco duplicado entre laboratórios', function () {
    $first = createCentralTenant(['database_name' => 'sislac_t_unique']);
    $second = createCentralTenant(['database_name' => 'sislac_t_unique']);

    DB::connection('central')->table('tenants')->insert($first);

    expect(fn () => DB::connection('central')->table('tenants')->insert($second))
        ->toThrow(QueryException::class);
});

it('impede exclusão de laboratório que ainda possui membership', function () {
    $user = User::factory()->create();
    $tenant = createCentralTenant();

    DB::connection('central')->table('tenants')->insert($tenant);
    DB::connection('central')->table('memberships')->insert(
        createCentralMembership((string) $user->id, $tenant['id']),
    );

    expect(fn () => DB::connection('central')->table('tenants')
        ->where('id', $tenant['id'])
        ->delete())
        ->toThrow(QueryException::class);
});

it('mantém os índices necessários para resolver vínculos sem varredura desnecessária', function () {
    $indexNames = DB::connection('central')->table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'memberships')
        ->pluck('indexname')
        ->all();

    expect($indexNames)
        ->toContain('memberships_user_id_tenant_id_unique')
        ->toContain('memberships_user_id_status_index')
        ->toContain('memberships_tenant_id_status_index');
});

it('mantém auditoria de plataforma sem coluna de atualização ou exclusão lógica', function () {
    $columns = DB::connection('central')->table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'platform_audit')
        ->pluck('column_name')
        ->all();

    expect($columns)
        ->toContain('created_at')
        ->not->toContain('updated_at')
        ->not->toContain('deleted_at');
});

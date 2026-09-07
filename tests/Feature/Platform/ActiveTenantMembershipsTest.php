<?php

use App\Platform\Models\User;
use App\Platform\Queries\ActiveTenantMemberships;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('retorna somente tenants ativos vinculados ativamente ao usuário', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $activeTenantId = (string) Str::uuid();
    $suspendedTenantId = (string) Str::uuid();
    $otherTenantId = (string) Str::uuid();

    DB::connection('central')->table('tenants')->insert([
        [
            'id' => $activeTenantId,
            'name' => 'Laboratório Ativo',
            'code' => 'lab-ativo',
            'status' => 'active',
            'database_name' => 'sislac_t_active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $suspendedTenantId,
            'name' => 'Laboratório Suspenso',
            'code' => 'lab-suspenso',
            'status' => 'suspended',
            'database_name' => 'sislac_t_suspended',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $otherTenantId,
            'name' => 'Outro Laboratório',
            'code' => 'lab-outro',
            'status' => 'active',
            'database_name' => 'sislac_t_other',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::connection('central')->table('memberships')->insert([
        [
            'user_id' => $user->id,
            'tenant_id' => $activeTenantId,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'user_id' => $user->id,
            'tenant_id' => $suspendedTenantId,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'user_id' => $otherUser->id,
            'tenant_id' => $otherTenantId,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $query = new ActiveTenantMemberships;

    expect($query->forUser((string) $user->id))->toBe([$activeTenantId]);
});

it('ignora membership suspensa mesmo quando o tenant está ativo', function () {
    $user = User::factory()->create();
    $tenantId = (string) Str::uuid();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Ativo',
        'code' => 'lab-ativo-membership-suspensa',
        'status' => 'active',
        'database_name' => 'sislac_t_membership_suspended',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('central')->table('memberships')->insert([
        'user_id' => $user->id,
        'tenant_id' => $tenantId,
        'role' => 'admin',
        'status' => 'suspended',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $query = new ActiveTenantMemberships;

    expect($query->forUser((string) $user->id))->toBe([]);
});

it('resolve vínculos ativos com uma única consulta ao banco central', function () {
    $user = User::factory()->create();
    $tenantId = (string) Str::uuid();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Ativo',
        'code' => 'lab-query-count',
        'status' => 'active',
        'database_name' => 'sislac_t_query_count',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('central')->table('memberships')->insert([
        'user_id' => $user->id,
        'tenant_id' => $tenantId,
        'role' => 'admin',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $connection = DB::connection('central');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $query = new ActiveTenantMemberships;
    $tenantIds = $query->forUser((string) $user->id);

    $executedQueries = $connection->getQueryLog();
    $connection->disableQueryLog();

    expect($tenantIds)->toBe([$tenantId])
        ->and($executedQueries)->toHaveCount(1);
});

<?php

use App\Platform\Authorization\MembershipAuthorizer;
use App\Platform\Authorization\TenantPermission;
use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pacienteAuthorizationTenant(): Tenant
{
    $id = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $id,
        'name' => 'Laboratório Autorização',
        'code' => 'authz-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => 'sislac_t_authz_'.Str::lower(Str::random(8)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Tenant::query()->findOrFail($id);
}

function pacienteAuthorizationMembership(User $user, Tenant $tenant, string $role, array $extra = [], array $revoked = [], string $status = 'active'): void
{
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $user->getKey(),
        'tenant_id' => $tenant->getKey(),
        'role' => $role,
        'status' => $status,
        'permissions_extra' => json_encode($extra, JSON_THROW_ON_ERROR),
        'permissions_revoked' => json_encode($revoked, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('replica as permissões padrão dos papéis para pacientes', function (string $role, bool $view, bool $create, bool $edit) {
    $user = User::factory()->create();
    $tenant = pacienteAuthorizationTenant();
    pacienteAuthorizationMembership($user, $tenant, $role);
    $authorizer = app(MembershipAuthorizer::class);

    expect($authorizer->allows((string) $user->getKey(), (string) $tenant->getKey(), TenantPermission::ViewPatients))->toBe($view)
        ->and($authorizer->allows((string) $user->getKey(), (string) $tenant->getKey(), TenantPermission::CreatePatient))->toBe($create)
        ->and($authorizer->allows((string) $user->getKey(), (string) $tenant->getKey(), TenantPermission::EditPatient))->toBe($edit);
})->with([
    'admin' => ['admin', true, true, true],
    'analista' => ['analista', true, false, false],
    'recepcionista' => ['recepcionista', true, true, true],
    'financeiro' => ['financeiro', true, false, false],
    'papel sem default' => ['coleta', false, false, false],
]);

it('faz revogação explícita vencer até mesmo admin', function () {
    $user = User::factory()->create();
    $tenant = pacienteAuthorizationTenant();
    pacienteAuthorizationMembership($user, $tenant, 'admin', [], ['editar_paciente']);

    expect(app(MembershipAuthorizer::class)->allows(
        (string) $user->getKey(),
        (string) $tenant->getKey(),
        TenantPermission::EditPatient,
    ))->toBeFalse();
});

it('permite concessão extra quando não revogada', function () {
    $user = User::factory()->create();
    $tenant = pacienteAuthorizationTenant();
    pacienteAuthorizationMembership($user, $tenant, 'coleta', ['cadastrar_paciente']);

    expect(app(MembershipAuthorizer::class)->allows(
        (string) $user->getKey(),
        (string) $tenant->getKey(),
        TenantPermission::CreatePatient,
    ))->toBeTrue();
});

it('nega membership suspensa mesmo com papel admin', function () {
    $user = User::factory()->create();
    $tenant = pacienteAuthorizationTenant();
    pacienteAuthorizationMembership($user, $tenant, 'admin', [], [], 'suspended');

    expect(app(MembershipAuthorizer::class)->allows(
        (string) $user->getKey(),
        (string) $tenant->getKey(),
        TenantPermission::ViewPatients,
    ))->toBeFalse();
});

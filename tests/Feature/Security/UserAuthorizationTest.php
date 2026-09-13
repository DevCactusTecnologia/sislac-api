<?php

use App\Platform\Authorization\TenantPermission;
use App\Platform\Authorization\UserAuthorizer;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('aplica os defaults aprovados diretamente no usuário', function (string $role, TenantPermission $permission, bool $allowed) {
    $user = User::factory()->create([
        'role' => $role,
        'status' => 'active',
    ]);

    expect(app(UserAuthorizer::class)->allows($user, $permission))->toBe($allowed);
})->with([
    'admin pode tudo' => ['admin', TenantPermission::SystemSettings, true],
    'recepção visualiza pacientes' => ['recepcionista', TenantPermission::ViewPatients, true],
    'recepção registra coleta' => ['recepcionista', TenantPermission::RegisterCollection, true],
    'recepção não administra financeiro' => ['recepcionista', TenantPermission::FinancialManagement, false],
    'analista registra coleta' => ['analista', TenantPermission::RegisterCollection, true],
    'analista analisa amostra' => ['analista', TenantPermission::AnalyzeSample, true],
    'analista não cadastra paciente' => ['analista', TenantPermission::CreatePatient, false],
    'financeiro visualiza financeiro' => ['financeiro', TenantPermission::ViewFinance, true],
    'financeiro administra financeiro' => ['financeiro', TenantPermission::FinancialManagement, true],
    'papel desconhecido não recebe default' => ['coleta', TenantPermission::ViewPatients, false],
]);

it('faz revogação explícita vencer o papel e concessão extra ampliar o papel', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'permissions_extra' => ['analisar_amostra'],
        'permissions_revoked' => ['editar_paciente'],
    ]);

    $authorizer = app(UserAuthorizer::class);

    expect($authorizer->allows($user, TenantPermission::EditPatient))->toBeFalse()
        ->and($authorizer->allows($user, TenantPermission::AnalyzeSample))->toBeTrue();
});

it('nega usuário suspenso mesmo com papel admin', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'suspended',
    ]);

    expect(app(UserAuthorizer::class)->allows($user, TenantPermission::ViewPatients))->toBeFalse();
});

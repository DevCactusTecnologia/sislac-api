<?php

use App\Platform\Supabase\SupabasePermissionAuthorizer;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    resetSupabaseFixture();
    configureSupabaseTestUser($this, ['visualizar_pacientes']);

    Route::middleware(['supabase.auth', 'permission:visualizar_pacientes'])
        ->get('/_test/supabase-permission-allowed', fn () => response()->json(['ok' => true]));

    Route::middleware(['supabase.auth', 'permission:editar_paciente'])
        ->get('/_test/supabase-permission-denied', fn () => response()->json(['ok' => true]));
});

it('consulta a função canônica has_permission com UUID e permissão explícitos', function () {
    $authorizer = app(SupabasePermissionAuthorizer::class);

    expect($authorizer->allows(
        '11111111-1111-4111-8111-111111111111',
        'visualizar_pacientes',
    ))->toBeTrue()
        ->and($authorizer->allows(
            '11111111-1111-4111-8111-111111111111',
            'editar_paciente',
        ))->toBeFalse()
        ->and($authorizer->allows(
            '22222222-2222-4222-8222-222222222222',
            'visualizar_pacientes',
        ))->toBeFalse();
});

it('permite somente quando has_permission retorna verdadeiro', function () {
    $this->getJson('/_test/supabase-permission-allowed')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('nega permissão ausente sem confiar em metadados editáveis do token', function () {
    $this->getJson('/_test/supabase-permission-denied')
        ->assertForbidden()
        ->assertJson(['message' => 'Acesso não autorizado.']);

    $source = file_get_contents(app_path('Http/Middleware/RequireSupabasePermission.php'));

    expect($source)->toBeString()
        ->not->toContain('user_metadata')
        ->not->toContain('app_metadata');
});

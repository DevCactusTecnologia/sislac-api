<?php

use App\Platform\Supabase\SupabasePermissionAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Autorização canônica requer PostgreSQL.');
    }

    Http::preventStrayRequests();
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');

    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION public.has_permission(_user_id uuid, _permission text)
        RETURNS boolean
        LANGUAGE sql
        STABLE
        AS $$
            SELECT _user_id = '11111111-1111-4111-8111-111111111111'::uuid
               AND _permission = 'visualizar_pacientes'
        $$
    SQL);

    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => '11111111-1111-4111-8111-111111111111',
            'email' => 'analista@example.test',
            'user_metadata' => [
                'role' => 'admin',
                'permissions' => ['editar_paciente'],
            ],
        ], 200),
    ]);

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
    $this->withToken('valid-token')
        ->getJson('/_test/supabase-permission-allowed')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('nega permissão ausente mesmo quando user_metadata tenta concedê-la', function () {
    $this->withToken('valid-token')
        ->getJson('/_test/supabase-permission-denied')
        ->assertForbidden()
        ->assertJson(['message' => 'Acesso não autorizado.']);

    $source = file_get_contents(app_path('Http/Middleware/RequireSupabasePermission.php'));

    expect($source)->toBeString()
        ->not->toContain('user_metadata')
        ->not->toContain('app_metadata');
});

<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('O contrato de contexto RLS exige PostgreSQL real.');
    }

    DB::selectOne('SELECT pg_advisory_lock(90711001)');

    try {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
                    CREATE ROLE authenticated NOLOGIN;
                END IF;
            END
            $$;
        SQL);
        DB::statement('CREATE TABLE IF NOT EXISTS public.__supabase_rls_rollback_probe (id uuid PRIMARY KEY)');
        DB::statement('GRANT SELECT, INSERT, DELETE ON public.__supabase_rls_rollback_probe TO authenticated');
    } finally {
        DB::selectOne('SELECT pg_advisory_unlock(90711001)');
    }

    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');

    Http::preventStrayRequests();
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => '22222222-2222-4222-8222-222222222222',
            'email' => 'rls@example.test',
        ], 200),
    ]);

    Route::middleware(['supabase.auth', 'supabase.db'])
        ->get('/_test/supabase-db-context', function (Request $request) {
            $state = DB::selectOne(<<<'SQL'
                SELECT
                    current_user AS db_role,
                    current_setting('request.jwt.claim.sub', true) AS jwt_sub,
                    current_setting('request.jwt.claim.role', true) AS jwt_role,
                    current_setting('request.jwt.claims', true) AS jwt_claims
            SQL);

            return response()->json([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'db_role' => $state?->db_role,
                'jwt_sub' => $state?->jwt_sub,
                'jwt_role' => $state?->jwt_role,
                'jwt_claims' => json_decode((string) ($state?->jwt_claims ?? ''), true),
            ]);
        });
});

it('aplica identidade Supabase e role authenticated somente dentro da transação da request', function () {
    $userId = '22222222-2222-4222-8222-222222222222';

    $this->withToken('valid-rls-token')
        ->getJson('/_test/supabase-db-context')
        ->assertOk()
        ->assertJsonPath('user_id', $userId)
        ->assertJsonPath('db_role', 'authenticated')
        ->assertJsonPath('jwt_sub', $userId)
        ->assertJsonPath('jwt_role', 'authenticated')
        ->assertJsonPath('jwt_claims.sub', $userId)
        ->assertJsonPath('jwt_claims.role', 'authenticated')
        ->assertJsonPath('jwt_claims.email', 'rls@example.test');

    $after = DB::selectOne(<<<'SQL'
        SELECT
            current_user AS db_role,
            current_setting('request.jwt.claim.sub', true) AS jwt_sub,
            current_setting('request.jwt.claim.role', true) AS jwt_role
    SQL);

    expect((string) ($after?->db_role ?? ''))->not->toBe('authenticated')
        ->and((string) ($after?->jwt_sub ?? ''))->not->toBe($userId)
        ->and((string) ($after?->jwt_role ?? ''))->not->toBe('authenticated');
});

it('faz rollback e não deixa contexto RLS vazar quando o endpoint falha', function () {
    $probeId = '33333333-3333-4333-8333-333333333333';
    DB::table('__supabase_rls_rollback_probe')->where('id', $probeId)->delete();

    Route::middleware(['supabase.auth', 'supabase.db'])
        ->get('/_test/supabase-db-context-failure', function () use ($probeId) {
            DB::table('__supabase_rls_rollback_probe')->insert(['id' => $probeId]);

            throw new RuntimeException('falha simulada');
        });

    $this->withToken('valid-rls-token')
        ->getJson('/_test/supabase-db-context-failure')
        ->assertStatus(500);

    $after = DB::selectOne(<<<'SQL'
        SELECT
            current_user AS db_role,
            current_setting('request.jwt.claim.sub', true) AS jwt_sub
    SQL);

    expect((string) ($after?->db_role ?? ''))->not->toBe('authenticated')
        ->and((string) ($after?->jwt_sub ?? ''))->not->toBe('22222222-2222-4222-8222-222222222222')
        ->and(DB::table('__supabase_rls_rollback_probe')->where('id', $probeId)->exists())->toBeFalse();
});

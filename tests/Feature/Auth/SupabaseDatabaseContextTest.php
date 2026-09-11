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

            CREATE SCHEMA IF NOT EXISTS auth;

            CREATE OR REPLACE FUNCTION auth.uid()
            RETURNS uuid
            LANGUAGE sql
            STABLE
            AS $$
                SELECT COALESCE(
                    NULLIF(current_setting('request.jwt.claim.sub', true), ''),
                    (NULLIF(current_setting('request.jwt.claims', true), '')::jsonb ->> 'sub')
                )::uuid
            $$;

            CREATE OR REPLACE FUNCTION auth.role()
            RETURNS text
            LANGUAGE sql
            STABLE
            AS $$
                SELECT COALESCE(
                    NULLIF(current_setting('request.jwt.claim.role', true), ''),
                    (NULLIF(current_setting('request.jwt.claims', true), '')::jsonb ->> 'role')
                )::text
            $$;

            GRANT USAGE ON SCHEMA auth TO authenticated;
            GRANT EXECUTE ON FUNCTION auth.uid() TO authenticated;
            GRANT EXECUTE ON FUNCTION auth.role() TO authenticated;
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
                    auth.uid()::text AS auth_uid,
                    auth.role() AS auth_role,
                    current_setting('request.jwt.claims', true) AS jwt_claims
            SQL);

            return response()->json([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'db_role' => $state?->db_role,
                'auth_uid' => $state?->auth_uid,
                'auth_role' => $state?->auth_role,
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
        ->assertJsonPath('auth_uid', $userId)
        ->assertJsonPath('auth_role', 'authenticated')
        ->assertJsonPath('jwt_claims.sub', $userId)
        ->assertJsonPath('jwt_claims.role', 'authenticated')
        ->assertJsonPath('jwt_claims.email', 'rls@example.test');

    $after = DB::selectOne(<<<'SQL'
        SELECT
            current_user AS db_role,
            auth.uid()::text AS auth_uid,
            auth.role() AS auth_role
    SQL);

    expect((string) ($after?->db_role ?? ''))->not->toBe('authenticated')
        ->and($after?->auth_uid)->toBeNull()
        ->and($after?->auth_role)->toBeNull();
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
            auth.uid()::text AS auth_uid,
            auth.role() AS auth_role
    SQL);

    expect((string) ($after?->db_role ?? ''))->not->toBe('authenticated')
        ->and($after?->auth_uid)->toBeNull()
        ->and($after?->auth_role)->toBeNull()
        ->and(DB::table('__supabase_rls_rollback_probe')->where('id', $probeId)->exists())->toBeFalse();
});

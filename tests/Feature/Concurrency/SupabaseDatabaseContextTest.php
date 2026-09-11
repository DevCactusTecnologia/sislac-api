<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Contexto RLS do Supabase requer PostgreSQL.');
    }

    DB::selectOne('select pg_advisory_lock(91120260911)');

    try {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
                    CREATE ROLE authenticated NOLOGIN;
                END IF;

                EXECUTE format('GRANT authenticated TO %I', current_user);
            END
            $$
        SQL);
    } finally {
        DB::selectOne('select pg_advisory_unlock(91120260911)');
    }

    DB::unprepared(<<<'SQL'
        CREATE SCHEMA IF NOT EXISTS auth;

        CREATE OR REPLACE FUNCTION auth.uid()
        RETURNS uuid
        LANGUAGE sql
        STABLE
        AS $$
            SELECT NULLIF(current_setting('request.jwt.claim.sub', true), '')::uuid
        $$;

        GRANT USAGE ON SCHEMA auth TO authenticated;
        GRANT EXECUTE ON FUNCTION auth.uid() TO authenticated;

        DROP TABLE IF EXISTS supabase_context_probe;
        CREATE TABLE supabase_context_probe (marker text PRIMARY KEY);
        GRANT SELECT, INSERT ON supabase_context_probe TO authenticated;
    SQL);

    Http::preventStrayRequests();
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');

    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => '11111111-1111-4111-8111-111111111111',
            'email' => 'analista@example.test',
        ], 200),
    ]);

    Route::middleware(['supabase.auth', 'supabase.db'])
        ->get('/_test/supabase-db-context', function () {
            $row = DB::selectOne(<<<'SQL'
                select current_user as db_user,
                       auth.uid()::text as uid,
                       current_setting('request.jwt.claim.role', true) as jwt_role
            SQL);

            return response()->json([
                'db_user' => $row?->db_user,
                'uid' => $row?->uid,
                'jwt_role' => $row?->jwt_role,
            ]);
        });

    Route::middleware(['supabase.auth', 'supabase.db'])
        ->post('/_test/supabase-db-rollback', function () {
            DB::table('supabase_context_probe')->insert(['marker' => 'must-rollback']);

            return response()->json(['message' => 'inválido'], 422);
        });
});

afterEach(function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::unprepared('DROP TABLE IF EXISTS supabase_context_probe');
    }
});

it('aplica role e claims somente durante a transação autenticada', function () {
    $outsideBefore = DB::selectOne(<<<'SQL'
        select current_user as db_user,
               current_setting('request.jwt.claim.sub', true) as sub
    SQL);

    $this->withToken('valid-token')
        ->getJson('/_test/supabase-db-context')
        ->assertOk()
        ->assertJson([
            'db_user' => 'authenticated',
            'uid' => '11111111-1111-4111-8111-111111111111',
            'jwt_role' => 'authenticated',
        ]);

    $outsideAfter = DB::selectOne(<<<'SQL'
        select current_user as db_user,
               current_setting('request.jwt.claim.sub', true) as sub
    SQL);

    expect($outsideBefore?->db_user)->not->toBe('authenticated')
        ->and($outsideAfter?->db_user)->toBe($outsideBefore?->db_user)
        ->and($outsideAfter?->sub)->toBeIn([null, '']);
});

it('faz rollback quando a resposta HTTP representa falha', function () {
    $this->withToken('valid-token')
        ->postJson('/_test/supabase-db-rollback')
        ->assertUnprocessable();

    expect(DB::table('supabase_context_probe')->count())->toBe(0);
});

it('não inicia contexto de banco sem principal Supabase autenticado', function () {
    Route::middleware('supabase.db')
        ->get('/_test/supabase-db-without-principal', fn () => response()->json(['ok' => true]));

    $this->getJson('/_test/supabase-db-without-principal')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Não autenticado.']);
});

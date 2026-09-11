<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

function resetSupabaseFixture(): void
{
    DB::unprepared(<<<'SQL'
        TRUNCATE TABLE
            atendimento_audit,
            financeiro_estornos,
            financeiro_saidas,
            atendimento_pagamentos,
            atendimento_exames,
            atendimentos,
            pacientes,
            friendly_id_counters,
            caixa_sessoes,
            protocolo_sequence,
            test_permissions
        RESTART IDENTITY CASCADE
    SQL);

    DB::table('lab_config')->update([
        'rotina_fluxo_modo' => 'completo',
        'updated_at' => now(),
    ]);
}

/** @param list<string> $permissions */
function configureSupabaseTestUser(
    TestCase $test,
    array $permissions,
    string $userId = '11111111-1111-4111-8111-111111111111',
    string $email = 'integration@example.test',
): string {
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');

    Http::preventStrayRequests();
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $userId,
            'email' => $email,
        ], 200),
    ]);

    setSupabaseTestPermissions($userId, $permissions);

    $test->withHeader('Origin', 'https://sislac.com.br');
    $test->withToken('valid-supabase-test-token');

    return $userId;
}

/** @param list<string> $permissions */
function setSupabaseTestPermissions(string $userId, array $permissions): void
{
    DB::table('test_permissions')->where('user_id', $userId)->delete();

    foreach ($permissions as $permission) {
        DB::table('test_permissions')->insert([
            'user_id' => $userId,
            'permission' => $permission,
            'allowed' => true,
        ]);
    }
}

function denySupabasePermission(string $userId, string $permission): void
{
    DB::table('test_permissions')->updateOrInsert(
        ['user_id' => $userId, 'permission' => $permission],
        ['allowed' => false],
    );
}

function supabaseTestPdo(): PDO
{
    $pdo = DB::connection()->getPdo();

    if (! $pdo instanceof PDO) {
        throw new RuntimeException('PDO PostgreSQL de teste indisponível.');
    }

    return $pdo;
}

function newSupabaseTestPdo(): PDO
{
    $config = config('database.connections.pgsql');

    if (! is_array($config)) {
        throw new RuntimeException('Conexão pgsql de teste não configurada.');
    }

    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (string) ($config['port'] ?? '5432'),
            (string) ($config['database'] ?? 'postgres'),
        ),
        (string) ($config['username'] ?? ''),
        (string) ($config['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

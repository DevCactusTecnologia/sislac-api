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

    DB::table('lab_config')->where('singleton_key', 1)->update([
        'rotina_fluxo_modo' => 'completo',
        'updated_at' => now(),
    ]);
}

/** @param list<string> $permissions */
function configureSupabaseTestUser(TestCase $test, array $permissions, string $userId = '11111111-1111-4111-8111-111111111111'): string
{
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');

    Http::preventStrayRequests();
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $userId,
            'email' => 'integration@example.test',
        ], 200),
    ]);

    foreach ($permissions as $permission) {
        DB::table('test_permissions')->updateOrInsert(
            ['user_id' => $userId, 'permission' => $permission],
            ['allowed' => true],
        );
    }

    $test->withHeader('Origin', 'https://sislac.com.br');
    $test->withToken('valid-supabase-test-token');

    return $userId;
}

function denySupabasePermission(string $userId, string $permission): void
{
    DB::table('test_permissions')->updateOrInsert(
        ['user_id' => $userId, 'permission' => $permission],
        ['allowed' => false],
    );
}

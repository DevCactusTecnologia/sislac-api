<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentoAuthDatabase = 'sislac_t_atauth_'.Str::lower(Str::random(10));
    atendimentoAuthControlConnection()->exec('CREATE DATABASE "'.$this->atendimentoAuthDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Autorização Atendimentos',
        'code' => 'atauth-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentoAuthDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->atendimentoAuthTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->atendimentoAuthTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->atendimentoAuthUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->atendimentoAuthUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'recepcionista',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->actingAs($this->atendimentoAuthUser, 'web');

    $created = $this->postJson('/api/atendimentos', [
        'paciente_nome' => 'Paciente Autorização',
        'paciente_cpf' => '12345678901',
        'idempotency_key' => (string) Str::uuid(),
        'exames' => [[
            'nome_exame' => 'Hemograma',
            'valor' => '100.00',
            'tipo_processo' => 'INTERNO',
            'amostra_seq' => 1,
        ]],
    ])->assertCreated();

    $this->atendimentoAuthId = (int) $created->json('atendimento_id');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    atendimentoAuthControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentoAuthDatabase.'" WITH (FORCE)');
});

function atendimentoAuthControlConnection(?string $database = null): PDO
{
    $config = config('database.connections.central');
    $database ??= 'postgres';

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $database),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

it('exige editar_atendimento para alteração clínica ou cadastral normal', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->atendimentoAuthUser->getKey())
        ->where('tenant_id', $this->atendimentoAuthTenant->getKey())
        ->update(['role' => 'analista']);

    $this->patchJson('/api/atendimentos/'.$this->atendimentoAuthId, [
        'solicitante' => 'Sem Permissão',
    ])->assertForbidden();
});

it('exige cancelar_atendimento para cancelamento mesmo quando usuário pode editar', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->atendimentoAuthUser->getKey())
        ->where('tenant_id', $this->atendimentoAuthTenant->getKey())
        ->update(['permissions_revoked' => json_encode(['cancelar_atendimento'], JSON_THROW_ON_ERROR)]);

    $this->patchJson('/api/atendimentos/'.$this->atendimentoAuthId, [
        'cancelar' => true,
        'motivo_cancelamento' => 'Tentativa sem permissão',
    ])->assertForbidden();
});

it('permite operação exclusivamente financeira com registrar_pagamento sem editar_atendimento', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->atendimentoAuthUser->getKey())
        ->where('tenant_id', $this->atendimentoAuthTenant->getKey())
        ->update(['role' => 'financeiro']);

    $this->patchJson('/api/atendimentos/'.$this->atendimentoAuthId, [
        'pagamentos' => [[
            'tipo' => 'PIX',
            'valor' => '30.00',
            'observacao' => 'Pagamento financeiro',
        ]],
    ])->assertOk()
        ->assertJsonPath('data.status_pagamento', 'Pagamento parcial');
});

it('operação mista exige editar_atendimento e registrar_pagamento', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->atendimentoAuthUser->getKey())
        ->where('tenant_id', $this->atendimentoAuthTenant->getKey())
        ->update(['role' => 'financeiro']);

    $this->patchJson('/api/atendimentos/'.$this->atendimentoAuthId, [
        'solicitante' => 'Alteração Mista',
        'pagamentos' => [[
            'tipo' => 'PIX',
            'valor' => '30.00',
        ]],
    ])->assertForbidden();
});

it('não expõe exclusão física de Atendimentos', function () {
    $this->deleteJson('/api/atendimentos/'.$this->atendimentoAuthId)
        ->assertMethodNotAllowed();
});

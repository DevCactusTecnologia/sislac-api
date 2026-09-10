<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->saidasEstornoDatabase = 'sislac_t_saidas_estorno_'.Str::lower(Str::random(8));
    saidasEstornoControlConnection()->exec('CREATE DATABASE "'.$this->saidasEstornoDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Estorno Saídas',
        'code' => 'saidas-estorno-'.Str::lower(Str::random(7)),
        'status' => 'active',
        'database_name' => $this->saidasEstornoDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->saidasEstornoTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->saidasEstornoTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->saidasEstornoUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->saidasEstornoUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'financeiro',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Http::preventStrayRequests();
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $this->saidasEstornoUser->id,
            'email' => $this->saidasEstornoUser->email,
        ], 200),
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withToken('valid-saidas-estorno-token');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    saidasEstornoControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->saidasEstornoDatabase.'" WITH (FORCE)');
});

function saidasEstornoControlConnection(?string $database = null): PDO
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

/** @param array<string, mixed> $overrides */
function validSaidaEstornoPayload(array $overrides = []): array
{
    return array_merge([
        'descricao' => 'Despesa para estorno',
        'valor' => '120.50',
        'tipo_despesa' => 'Conta',
        'destino_pagamento' => 'Fornecedor',
    ], $overrides);
}

it('estorna saída aberta formalmente sem apagar o lançamento', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();

    $id = (int) $saida->json('data.id');
    $protocolo = (string) $saida->json('data.protocolo');

    $response = $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => '  Lançamento   indevido  ',
    ])
        ->assertOk()
        ->assertJsonPath('data.saida.id', $id)
        ->assertJsonPath('data.saida.protocolo', $protocolo)
        ->assertJsonPath('data.saida.valor', '120.50')
        ->assertJsonPath('data.saida.status', 'cancelada')
        ->assertJsonPath('data.saida.foi_pago', false)
        ->assertJsonPath('data.estorno.origem_tipo', 'saida')
        ->assertJsonPath('data.estorno.origem_id', $id)
        ->assertJsonPath('data.estorno.motivo', 'Lançamento indevido')
        ->assertJsonPath('data.estorno.valor', '120.50');

    expect((int) $response->json('data.estorno.id'))->toBeGreaterThan(0);

    $pdo = saidasEstornoControlConnection($this->saidasEstornoDatabase);
    expect((int) $pdo->query('SELECT count(*) FROM financeiro_saidas')?->fetchColumn())->toBe(1)
        ->and((int) $pdo->query("SELECT count(*) FROM financeiro_estornos WHERE origem_tipo = 'saida' AND origem_id = {$id}")?->fetchColumn())->toBe(1);
});

it('estorna saída paga preservando data de pagamento e vínculo histórico do caixa', function () {
    $pdo = saidasEstornoControlConnection($this->saidasEstornoDatabase);
    $caixaId = (int) $pdo->query("INSERT INTO caixa_sessoes (unidade_id, valor_abertura) VALUES ('und-001', 0) RETURNING id")?->fetchColumn();

    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();
    $id = (int) $saida->json('data.id');

    $paga = $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => 'Dinheiro',
        'data_pagamento' => '2026-09-10',
    ])
        ->assertOk()
        ->assertJsonPath('data.caixa_sessao_id', $caixaId);

    $protocolo = (string) $paga->json('data.protocolo');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Pagamento duplicado',
    ])
        ->assertOk()
        ->assertJsonPath('data.saida.protocolo', $protocolo)
        ->assertJsonPath('data.saida.status', 'cancelada')
        ->assertJsonPath('data.saida.foi_pago', false)
        ->assertJsonPath('data.saida.data_pagamento', '2026-09-10')
        ->assertJsonPath('data.saida.caixa_sessao_id', $caixaId)
        ->assertJsonPath('data.estorno.motivo', 'Pagamento duplicado');
});

it('recusa estorno sem motivo não vazio', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();
    $id = (int) $saida->json('data.id');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', ['motivo' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');
});

it('recusa segundo estorno da mesma saída', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();
    $id = (int) $saida->json('data.id');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Primeiro estorno',
    ])->assertOk();

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Segundo estorno',
    ])->assertStatus(409);

    $pdo = saidasEstornoControlConnection($this->saidasEstornoDatabase);
    expect((int) $pdo->query("SELECT count(*) FROM financeiro_estornos WHERE origem_tipo = 'saida' AND origem_id = {$id}")?->fetchColumn())->toBe(1);
});

it('retorna 404 ao tentar estornar saída inexistente', function () {
    $this->postJson('/api/financeiro/saidas/999999/estorno', [
        'motivo' => 'Não existe',
    ])->assertNotFound();
});

it('exige gestão financeira para estornar saída', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();
    $id = (int) $saida->json('data.id');

    DB::connection('central')->table('memberships')
        ->where('user_id', $this->saidasEstornoUser->getKey())
        ->where('tenant_id', $this->saidasEstornoTenant->getKey())
        ->update([
            'role' => 'recepcionista',
            'permissions_extra' => json_encode(['visualizar_financeiro'], JSON_THROW_ON_ERROR),
        ]);

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Sem permissão',
    ])->assertForbidden();
});

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
    $this->saidasEstornoDatabase = 'sislac_t_saida_est_'.Str::lower(Str::random(8));
    saidasEstornoControlConnection()->exec('CREATE DATABASE "'.$this->saidasEstornoDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Estorno Saídas',
        'code' => 'saida-est-'.Str::lower(Str::random(7)),
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
    $this->withToken('valid-saida-estorno-token');
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

function saidaEstornoPayload(array $overrides = []): array
{
    return array_merge([
        'descricao' => 'Despesa para estorno',
        'valor' => '75.40',
        'tipo_despesa' => 'Operacional',
        'destino_pagamento' => 'Fornecedor',
    ], $overrides);
}

it('estorna saída aberta sem apagar o lançamento original', function () {
    $created = $this->postJson('/api/financeiro/saidas', saidaEstornoPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => '  Lançamento   cadastrado em duplicidade ',
    ])->assertOk()
        ->assertJsonPath('data.saida.id', $id)
        ->assertJsonPath('data.saida.status', 'cancelada')
        ->assertJsonPath('data.saida.foi_pago', false)
        ->assertJsonPath('data.saida.valor', '75.40')
        ->assertJsonPath('data.estorno.origem_tipo', 'saida')
        ->assertJsonPath('data.estorno.origem_id', $id)
        ->assertJsonPath('data.estorno.motivo', 'Lançamento cadastrado em duplicidade')
        ->assertJsonPath('data.estorno.valor', '75.40');

    $pdo = saidasEstornoControlConnection($this->saidasEstornoDatabase);
    expect((int) $pdo->query('SELECT count(*) FROM financeiro_saidas')?->fetchColumn())->toBe(1)
        ->and((int) $pdo->query("SELECT count(*) FROM financeiro_estornos WHERE origem_tipo = 'saida'")?->fetchColumn())->toBe(1);
});

it('estorna saída paga preservando dados financeiros e vínculo histórico com caixa', function () {
    $session = $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-estorno-001',
        'valor_abertura' => '100.00',
    ])->assertCreated();
    $sessionId = (int) $session->json('data.id');

    $created = $this->postJson('/api/financeiro/saidas', saidaEstornoPayload([
        'data' => '2026-09-09T14:30:00-03:00',
        'status' => 'paga',
        'forma_pagamento' => 'Dinheiro',
        'data_pagamento' => '2026-09-09',
    ]))->assertCreated()
        ->assertJsonPath('data.caixa_sessao_id', $sessionId);

    $id = (int) $created->json('data.id');
    $before = $created->json('data');

    $response = $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Pagamento desfeito',
    ])->assertOk()
        ->assertJsonPath('data.saida.status', 'cancelada')
        ->assertJsonPath('data.saida.foi_pago', false)
        ->assertJsonPath('data.saida.valor', '75.40')
        ->assertJsonPath('data.saida.data_pagamento', '2026-09-09')
        ->assertJsonPath('data.saida.forma_pagamento', 'Dinheiro')
        ->assertJsonPath('data.saida.caixa_sessao_id', $sessionId);

    expect($response->json('data.saida.data'))->toBe($before['data']);
});

it('recusa segundo estorno e mantém exatamente um registro de estorno', function () {
    $created = $this->postJson('/api/financeiro/saidas', saidaEstornoPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Primeiro estorno',
    ])->assertOk();

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Segundo estorno',
    ])->assertConflict();

    $pdo = saidasEstornoControlConnection($this->saidasEstornoDatabase);
    $statement = $pdo->prepare("SELECT count(*) FROM financeiro_estornos WHERE origem_tipo = 'saida' AND origem_id = ?");
    $statement->execute([$id]);
    expect((int) $statement->fetchColumn())->toBe(1);
});

it('recusa motivo vazio no estorno', function () {
    $created = $this->postJson('/api/financeiro/saidas', saidaEstornoPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => '   ',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');
});

it('retorna 404 ao estornar saída inexistente', function () {
    $this->postJson('/api/financeiro/saidas/999999/estorno', [
        'motivo' => 'Registro inexistente',
    ])->assertNotFound();
});

it('exige gestão financeira para estornar saída', function () {
    $created = $this->postJson('/api/financeiro/saidas', saidaEstornoPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    DB::connection('central')->table('memberships')
        ->where('user_id', $this->saidasEstornoUser->getKey())
        ->where('tenant_id', $this->saidasEstornoTenant->getKey())
        ->update(['role' => 'recepcionista']);

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Sem autorização',
    ])->assertForbidden();

    $pdo = saidasEstornoControlConnection($this->saidasEstornoDatabase);
    $statement = $pdo->prepare('SELECT status FROM financeiro_saidas WHERE id = ?');
    $statement->execute([$id]);
    expect($statement->fetchColumn())->toBe('aberta');
});

it('saldo do caixa ignora saída paga que foi estornada', function () {
    $session = $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-estorno-001',
        'valor_abertura' => '100.00',
    ])->assertCreated();
    $sessionId = (int) $session->json('data.id');

    $created = $this->postJson('/api/financeiro/saidas', saidaEstornoPayload([
        'valor' => '25.00',
        'status' => 'paga',
        'forma_pagamento' => 'Dinheiro',
    ]))->assertCreated()
        ->assertJsonPath('data.caixa_sessao_id', $sessionId);
    $id = (int) $created->json('data.id');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Despesa cancelada antes do fechamento',
    ])->assertOk();

    $this->postJson('/api/financeiro/caixa/'.$sessionId.'/fechar')
        ->assertOk()
        ->assertJsonPath('data.saidas', '0.00')
        ->assertJsonPath('data.saldo_final', '100.00');
});

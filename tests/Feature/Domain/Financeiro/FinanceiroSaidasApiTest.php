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
    $this->saidasApiDatabase = 'sislac_t_saidas_api_'.Str::lower(Str::random(8));
    saidasApiControlConnection()->exec('CREATE DATABASE "'.$this->saidasApiDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório API Saídas',
        'code' => 'saidas-api-'.Str::lower(Str::random(7)),
        'status' => 'active',
        'database_name' => $this->saidasApiDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->saidasApiTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->saidasApiTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->saidasApiUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->saidasApiUser->getKey(),
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
            'id' => $this->saidasApiUser->id,
            'email' => $this->saidasApiUser->email,
        ], 200),
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withToken('valid-saidas-token');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    saidasApiControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->saidasApiDatabase.'" WITH (FORCE)');
});

function saidasApiControlConnection(?string $database = null): PDO
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

function validSaidaPayload(array $overrides = []): array
{
    return array_merge([
        'descricao' => 'Conta de energia',
        'valor' => '120.50',
        'tipo_despesa' => 'Conta',
        'destino_pagamento' => 'Concessionária',
    ], $overrides);
}

it('cria saída aberta com protocolo e estado definidos pelo servidor', function () {
    $response = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated()
        ->assertJsonPath('data.descricao', 'Conta de energia')
        ->assertJsonPath('data.valor', '120.50')
        ->assertJsonPath('data.status', 'aberta')
        ->assertJsonPath('data.foi_pago', false)
        ->assertJsonPath('data.data_pagamento', null)
        ->assertJsonPath('data.caixa_sessao_id', null);

    expect((string) $response->json('data.protocolo'))
        ->toMatch('/^SAI-\d{4}-\d{7}$/');

    $pdo = saidasApiControlConnection($this->saidasApiDatabase);
    expect((int) $pdo->query('SELECT count(*) FROM financeiro_saidas')?->fetchColumn())->toBe(1);
});

it('permite criar saída já paga e gera data de pagamento quando ausente', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'paga')
        ->assertJsonPath('data.foi_pago', true)
        ->assertJsonPath('data.data_pagamento', now()->toDateString())
        ->assertJsonPath('data.forma_pagamento', 'Crédito');
});

it('preserva data de pagamento explicitamente informada na criação paga', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'status' => 'paga',
        'forma_pagamento' => 'PIX',
        'data_pagamento' => '2026-09-09',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'paga')
        ->assertJsonPath('data.data_pagamento', '2026-09-09');
});

it('recusa criação direta como cancelada', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'status' => 'cancelada',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('recusa data de pagamento quando a saída nasce aberta', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'status' => 'aberta',
        'data_pagamento' => '2026-09-10',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('data_pagamento');
});

it('recusa campos de autoridade exclusiva do servidor', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'id' => 99,
        'protocolo' => 'SAI-CLIENTE',
        'assinatura_protocolo' => 'fake',
        'foi_pago' => true,
        'caixa_sessao_id' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'id',
            'protocolo',
            'assinatura_protocolo',
            'foi_pago',
            'caixa_sessao_id',
            'created_at',
            'updated_at',
        ]);
});

it('recusa valor zero negativo ou com mais de duas casas', function (string $valor) {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload(['valor' => $valor]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('valor');
})->with(['0.00', '-0.01', '10.001']);

it('normaliza espaços nos campos textuais de negócio', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'descricao' => '  Conta   de   água  ',
        'tipo_despesa' => '  Conta   pública ',
        'destino_pagamento' => '  Companhia   de Água ',
        'forma_pagamento' => '  Crédito  ',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.descricao', 'Conta de água')
        ->assertJsonPath('data.tipo_despesa', 'Conta pública')
        ->assertJsonPath('data.destino_pagamento', 'Companhia de Água')
        ->assertJsonPath('data.forma_pagamento', 'Crédito');
});

it('exige gestão financeira para criar saída', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->saidasApiUser->getKey())
        ->where('tenant_id', $this->saidasApiTenant->getKey())
        ->update(['role' => 'recepcionista']);

    $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertForbidden();

    $pdo = saidasApiControlConnection($this->saidasApiDatabase);
    expect((int) $pdo->query('SELECT count(*) FROM financeiro_saidas')?->fetchColumn())->toBe(0);
});

it('não expõe endpoint delete de saída', function () {
    $this->deleteJson('/api/financeiro/saidas/1')
        ->assertMethodNotAllowed();
});

it('corrige todos os campos de negócio enquanto a saída está aberta', function () {
    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'data' => '2026-09-08T15:30:00-03:00',
        'descricao' => '  Energia   unidade 02 ',
        'valor' => '98.75',
        'tipo_despesa' => '  Conta   pública ',
        'destino_pagamento' => '  Concessionária   Regional ',
        'data_vencimento' => '2026-09-25',
        'forma_pagamento' => '  Boleto  ',
    ])->assertOk()
        ->assertJsonPath('data.descricao', 'Energia unidade 02')
        ->assertJsonPath('data.valor', '98.75')
        ->assertJsonPath('data.tipo_despesa', 'Conta pública')
        ->assertJsonPath('data.destino_pagamento', 'Concessionária Regional')
        ->assertJsonPath('data.data_vencimento', '2026-09-25')
        ->assertJsonPath('data.forma_pagamento', 'Boleto')
        ->assertJsonPath('data.status', 'aberta')
        ->assertJsonPath('data.foi_pago', false);
});

it('efetiva saída aberta como paga e deixa o PostgreSQL definir o estado derivado', function () {
    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
    ])->assertOk()
        ->assertJsonPath('data.status', 'paga')
        ->assertJsonPath('data.foi_pago', true)
        ->assertJsonPath('data.data_pagamento', now()->toDateString())
        ->assertJsonPath('data.caixa_sessao_id', null);
});

it('vincula saída paga em dinheiro ou pix ao único caixa aberto', function (string $formaPagamento) {
    $session = $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-saidas-001',
        'valor_abertura' => '0.00',
    ])->assertCreated();
    $sessionId = (int) $session->json('data.id');

    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => $formaPagamento,
    ])->assertOk()
        ->assertJsonPath('data.status', 'paga')
        ->assertJsonPath('data.caixa_sessao_id', $sessionId);
})->with(['Dinheiro', 'PIX']);

it('não vincula saída paga por crédito mesmo com um caixa aberto', function () {
    $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-saidas-001',
        'valor_abertura' => '0.00',
    ])->assertCreated();

    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
    ])->assertOk()
        ->assertJsonPath('data.caixa_sessao_id', null);
});

it('permite pagar saída em dinheiro sem caixa aberto e mantém vínculo nulo', function () {
    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => 'Dinheiro',
    ])->assertOk()
        ->assertJsonPath('data.status', 'paga')
        ->assertJsonPath('data.caixa_sessao_id', null);
});

it('recusa edição comum de saída já paga sem alterar seus dados', function () {
    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
    ]))->assertCreated();
    $id = (int) $created->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'descricao' => 'Alteração indevida',
        'valor' => '10.00',
    ])->assertConflict();

    $pdo = saidasApiControlConnection($this->saidasApiDatabase);
    $statement = $pdo->prepare('SELECT descricao, valor::text, status FROM financeiro_saidas WHERE id = ?');
    $statement->execute([$id]);

    expect($statement->fetch(PDO::FETCH_ASSOC))->toMatchArray([
        'descricao' => 'Conta de energia',
        'valor' => '120.50',
        'status' => 'paga',
    ]);
});

it('recusa edição comum de saída cancelada sem alterar seus dados', function () {
    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $pdo = saidasApiControlConnection($this->saidasApiDatabase);
    $estorno = $pdo->prepare(<<<'SQL'
        INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor, criado_por)
        VALUES ('saida', ?, 'Fixture de cancelamento', 120.50, ?)
    SQL);
    $estorno->execute([$id, (string) $this->saidasApiUser->getKey()]);
    $cancel = $pdo->prepare("UPDATE financeiro_saidas SET status = 'cancelada' WHERE id = ?");
    $cancel->execute([$id]);

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'descricao' => 'Alteração indevida',
    ])->assertConflict();

    $statement = $pdo->prepare('SELECT descricao, status FROM financeiro_saidas WHERE id = ?');
    $statement->execute([$id]);

    expect($statement->fetch(PDO::FETCH_ASSOC))->toMatchArray([
        'descricao' => 'Conta de energia',
        'status' => 'cancelada',
    ]);
});

it('recusa cancelamento direto no patch', function () {
    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'cancelada',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('recusa campos server-side no patch de saída', function () {
    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'id' => 999,
        'protocolo' => 'SAI-CLIENTE',
        'assinatura_protocolo' => 'fake',
        'foi_pago' => true,
        'caixa_sessao_id' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'id',
            'protocolo',
            'assinatura_protocolo',
            'foi_pago',
            'caixa_sessao_id',
            'created_at',
            'updated_at',
        ]);
});

it('retorna 404 ao corrigir saída inexistente', function () {
    $this->patchJson('/api/financeiro/saidas/999999', [
        'descricao' => 'Inexistente',
    ])->assertNotFound();
});

it('exige gestão financeira para corrigir saída', function () {
    $created = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();
    $id = (int) $created->json('data.id');

    DB::connection('central')->table('memberships')
        ->where('user_id', $this->saidasApiUser->getKey())
        ->where('tenant_id', $this->saidasApiTenant->getKey())
        ->update(['role' => 'recepcionista']);

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'descricao' => 'Sem permissão',
    ])->assertForbidden();

    $pdo = saidasApiControlConnection($this->saidasApiDatabase);
    $statement = $pdo->prepare('SELECT descricao FROM financeiro_saidas WHERE id = ?');
    $statement->execute([$id]);
    expect($statement->fetchColumn())->toBe('Conta de energia');
});

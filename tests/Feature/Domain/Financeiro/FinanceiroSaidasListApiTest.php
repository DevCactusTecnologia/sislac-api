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
    $this->saidasListDatabase = 'sislac_t_saidas_list_'.Str::lower(Str::random(8));
    saidasListControlConnection()->exec('CREATE DATABASE "'.$this->saidasListDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Listagem Saídas',
        'code' => 'saidas-list-'.Str::lower(Str::random(7)),
        'status' => 'active',
        'database_name' => $this->saidasListDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->saidasListTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->saidasListTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->saidasListUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->saidasListUser->getKey(),
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
            'id' => $this->saidasListUser->id,
            'email' => $this->saidasListUser->email,
        ], 200),
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withToken('valid-saidas-list-token');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    saidasListControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->saidasListDatabase.'" WITH (FORCE)');
});

function saidasListControlConnection(?string $database = null): PDO
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

function createSaidaForList(object $test, array $overrides = []): array
{
    $payload = array_merge([
        'data' => '2026-09-10T12:00:00-03:00',
        'descricao' => 'Despesa operacional',
        'valor' => '75.40',
        'tipo_despesa' => 'Outros',
        'destino_pagamento' => 'Fornecedor padrão',
    ], $overrides);

    return $test->postJson('/api/financeiro/saidas', $payload)
        ->assertCreated()
        ->json('data');
}

it('lista saídas em ordem decrescente com cursor para a próxima página', function () {
    $first = createSaidaForList($this, ['descricao' => 'Mais antiga', 'data' => '2026-09-08T12:00:00-03:00']);
    $second = createSaidaForList($this, ['descricao' => 'Intermediária', 'data' => '2026-09-09T12:00:00-03:00']);
    $third = createSaidaForList($this, ['descricao' => 'Mais recente', 'data' => '2026-09-10T12:00:00-03:00']);

    $response = $this->getJson('/api/financeiro/saidas?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $third['id'])
        ->assertJsonPath('data.1.id', $second['id'])
        ->assertJsonStructure(['data', 'meta' => ['nextCursor']]);

    expect($response->json('meta.nextCursor.id'))->toBe($second['id']);
    expect($response->json('meta.nextCursor.data'))->not->toBeNull();
    expect($first['id'])->not->toBe($second['id']);
});

it('pagina de forma estável por data e id quando existem saídas na mesma data', function () {
    $one = createSaidaForList($this, ['descricao' => 'Mesmo instante 1']);
    $two = createSaidaForList($this, ['descricao' => 'Mesmo instante 2']);
    $three = createSaidaForList($this, ['descricao' => 'Mesmo instante 3']);

    $pageOne = $this->getJson('/api/financeiro/saidas?limit=2')->assertOk();
    $cursorData = (string) $pageOne->json('meta.nextCursor.data');
    $cursorId = (int) $pageOne->json('meta.nextCursor.id');

    $pageOneIds = collect($pageOne->json('data'))->pluck('id')->all();
    expect($pageOneIds)->toBe([$three['id'], $two['id']]);

    $pageTwo = $this->getJson('/api/financeiro/saidas?limit=2&cursor_data='.urlencode($cursorData).'&cursor_id='.$cursorId)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $one['id'])
        ->assertJsonPath('meta.nextCursor', null);

    expect($pageOneIds)->not->toContain($pageTwo->json('data.0.id'));
});

it('busca por protocolo descrição tipo de despesa e destino', function () {
    $target = createSaidaForList($this, [
        'descricao' => 'Energia solar unidade central',
        'tipo_despesa' => 'Infraestrutura elétrica',
        'destino_pagamento' => 'Fornecedor Solar Nordeste',
    ]);
    createSaidaForList($this, ['descricao' => 'Material de limpeza']);

    foreach ([
        $target['protocolo'],
        'energia solar',
        'infraestrutura elétrica',
        'solar nordeste',
    ] as $search) {
        $this->getJson('/api/financeiro/saidas?search='.urlencode($search))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target['id']);
    }
});

it('filtra saídas pelo status canônico', function () {
    $open = createSaidaForList($this, ['descricao' => 'Em aberto']);
    $paid = createSaidaForList($this, [
        'descricao' => 'Paga',
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
    ]);
    $cancelled = createSaidaForList($this, ['descricao' => 'Cancelada']);

    $this->postJson('/api/financeiro/saidas/'.$cancelled['id'].'/estorno', [
        'motivo' => 'Cancelamento para filtro',
    ])->assertOk();

    $this->getJson('/api/financeiro/saidas?status=aberta')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $open['id']);

    $this->getJson('/api/financeiro/saidas?status=paga')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $paid['id']);

    $this->getJson('/api/financeiro/saidas?status=cancelada')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cancelled['id']);
});

it('filtra saídas por intervalo de data', function () {
    createSaidaForList($this, ['descricao' => 'Fora antes', 'data' => '2026-09-07T12:00:00-03:00']);
    $inside = createSaidaForList($this, ['descricao' => 'Dentro', 'data' => '2026-09-09T12:00:00-03:00']);
    createSaidaForList($this, ['descricao' => 'Fora depois', 'data' => '2026-09-11T12:00:00-03:00']);

    $this->getJson('/api/financeiro/saidas?date_from='.urlencode('2026-09-08T00:00:00-03:00').'&date_to='.urlencode('2026-09-10T23:59:59-03:00'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inside['id']);
});

it('valida filtros e limita a paginação a no máximo cem itens', function () {
    $this->getJson('/api/financeiro/saidas?status=invalido&limit=101&cursor_data=2026-09-10')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'limit', 'cursor_id']);
});

it('exige visualizar financeiro para listar saídas', function () {
    createSaidaForList($this);

    DB::connection('central')->table('memberships')
        ->where('user_id', $this->saidasListUser->getKey())
        ->where('tenant_id', $this->saidasListTenant->getKey())
        ->update(['role' => 'recepcionista']);

    $this->getJson('/api/financeiro/saidas')
        ->assertForbidden();
});

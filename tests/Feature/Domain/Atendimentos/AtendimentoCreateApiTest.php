<?php

use App\Domain\Atendimentos\Actions\CreateAtendimento;
use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentoCreateDatabase = 'sislac_t_atcreate_'.Str::lower(Str::random(9));
    atendimentoCreateControlConnection()->exec('CREATE DATABASE "'.$this->atendimentoCreateDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Criação Atendimentos',
        'code' => 'atcreate-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentoCreateDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->atendimentoCreateTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->atendimentoCreateTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->atendimentoCreateUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->atendimentoCreateUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'recepcionista',
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
            'id' => $this->atendimentoCreateUser->id,
            'email' => $this->atendimentoCreateUser->email,
        ], 200),
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withToken('valid-atendimentos-token');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    atendimentoCreateControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentoCreateDatabase.'" WITH (FORCE)');
});

function atendimentoCreateControlConnection(?string $database = null): PDO
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

/** @return array<string, mixed> */
function validAtendimentoCreatePayload(?string $idempotencyKey = null): array
{
    return [
        'protocolo' => '7654321',
        'data' => '2026-09-08T10:00:00-03:00',
        'paciente_id' => 123,
        'paciente_nome' => 'Paciente Criação',
        'paciente_cpf' => '12345678901',
        'paciente_nascimento' => '1990-05-10',
        'solicitante' => 'Dra. Helena',
        'convenio_id' => 0,
        'convenio_nome' => 'Particular',
        'unidade_id' => 'und-001',
        'origem_atendimento' => 'INTERNO',
        'guia_numero' => 'GUIA-123',
        'guia_data' => '2026-09-08',
        'jejum' => true,
        'observacoes_assistente' => 'Observação segura',
        'risco_cardiovascular' => 'baixo',
        'prioridade_clinica' => 'normal',
        'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
        'status_atendimento' => 'Resultado Liberado',
        'status_pagamento' => 'Pagamento efetuado',
        'exames' => [
            [
                'nome_exame' => 'Hemograma',
                'status' => 'finalizado',
                'valor' => '100.00',
                'ordem' => 1,
                'tipo_processo' => 'INTERNO',
                'amostra_seq' => 1,
                'solicitante' => 'Dr. Solicitante do Exame',
            ],
            [
                'nome_exame' => 'Vitamina D Apoio',
                'status' => 'finalizado',
                'valor' => '50.00',
                'valor_original' => '60.00',
                'ordem' => 2,
                'tipo_processo' => 'TERCEIRIZADO',
                'amostra_seq' => 1,
            ],
        ],
    ];
}

it('cria pai e exames em uma única operação com campos protegidos server-side', function () {
    $response = $this->postJson('/api/atendimentos', validAtendimentoCreatePayload())
        ->assertCreated()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('duplicate', false)
        ->assertJsonPath('guia_numero', 'GUIA-123');

    $id = (int) $response->json('atendimento_id');
    $protocolo = (string) $response->json('protocolo');

    expect($id)->toBeGreaterThan(0)
        ->and($protocolo)->toMatch('/^\d{7}$/')
        ->and($protocolo)->not->toBe('7654321');

    $pdo = atendimentoCreateControlConnection($this->atendimentoCreateDatabase);
    $parent = $pdo->query(sprintf(
        'SELECT protocolo, status_atendimento, status_pagamento, subtotal::text, desconto_total::text, acrescimo_total::text, total::text FROM atendimentos WHERE id = %d',
        $id,
    ))?->fetch(PDO::FETCH_ASSOC);

    expect($parent)->toMatchArray([
        'protocolo' => $protocolo,
        'status_atendimento' => 'Amostra Analisada',
        'status_pagamento' => 'Pagamento pendente',
        'subtotal' => '160.00',
        'desconto_total' => '10.00',
        'acrescimo_total' => '0.00',
        'total' => '150.00',
    ]);

    $exames = $pdo->query(sprintf(
        'SELECT nome_exame, status, valor::text, valor_original::text, tipo_processo, solicitante FROM atendimento_exames WHERE atendimento_id = %d ORDER BY ordem',
        $id,
    ))?->fetchAll(PDO::FETCH_ASSOC);

    expect($exames)->toBe([
        [
            'nome_exame' => 'Hemograma',
            'status' => 'pendente',
            'valor' => '100.00',
            'valor_original' => '100.00',
            'tipo_processo' => 'INTERNO',
            'solicitante' => 'Dr. Solicitante do Exame',
        ],
        [
            'nome_exame' => 'Vitamina D Apoio',
            'status' => 'digitado',
            'valor' => '50.00',
            'valor_original' => '60.00',
            'tipo_processo' => 'TERCEIRIZADO',
            'solicitante' => '',
        ],
    ])
        ->and((int) $pdo->query('SELECT count(*) FROM atendimento_pagamentos WHERE atendimento_id = '.$id)?->fetchColumn())->toBe(0);
});

it('mantém concordância com o baseline quando paciente não possui cpf', function () {
    $payload = validAtendimentoCreatePayload();
    unset($payload['paciente_cpf']);

    $response = $this->postJson('/api/atendimentos', $payload)
        ->assertCreated();

    $id = (int) $response->json('atendimento_id');
    $pdo = atendimentoCreateControlConnection($this->atendimentoCreateDatabase);

    expect((string) $pdo->query("SELECT paciente_cpf FROM atendimentos WHERE id = {$id}")?->fetchColumn())
        ->toBe('');
});

it('repete a mesma idempotency key retornando o mesmo atendimento sem duplicar filhos', function () {
    $key = (string) Str::uuid();
    $payload = validAtendimentoCreatePayload($key);

    $first = $this->postJson('/api/atendimentos', $payload)
        ->assertCreated()
        ->assertJsonPath('duplicate', false);

    $second = $this->postJson('/api/atendimentos', $payload)
        ->assertOk()
        ->assertJsonPath('duplicate', true);

    expect($second->json('atendimento_id'))->toBe($first->json('atendimento_id'))
        ->and($second->json('protocolo'))->toBe($first->json('protocolo'));

    $pdo = atendimentoCreateControlConnection($this->atendimentoCreateDatabase);
    expect((int) $pdo->query("SELECT count(*) FROM atendimentos WHERE idempotency_key = '{$key}'")?->fetchColumn())->toBe(1)
        ->and((int) $pdo->query('SELECT count(*) FROM atendimento_exames')?->fetchColumn())->toBe(2)
        ->and((int) $pdo->query('SELECT count(*) FROM atendimento_pagamentos')?->fetchColumn())->toBe(0);
});

it('faz rollback integral quando um filho viola uma invariante do banco', function () {
    tenancy()->initialize($this->atendimentoCreateTenant);
    $payload = validAtendimentoCreatePayload();
    $payload['exames'][1]['tipo_processo'] = 'INVALIDO';

    expect(fn () => app(CreateAtendimento::class)->handle($payload))
        ->toThrow(QueryException::class);

    expect(DB::connection('tenant')->table('atendimentos')->count())->toBe(0)
        ->and(DB::connection('tenant')->table('atendimento_exames')->count())->toBe(0)
        ->and(DB::connection('tenant')->table('atendimento_pagamentos')->count())->toBe(0);
});

it('não permite que perfil sem criar_atendimento use o endpoint', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->atendimentoCreateUser->getKey())
        ->where('tenant_id', $this->atendimentoCreateTenant->getKey())
        ->update(['role' => 'analista']);

    $this->postJson('/api/atendimentos', validAtendimentoCreatePayload())
        ->assertForbidden();
});

it('respeita revogação explícita de criar_atendimento mesmo para recepcionista', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->atendimentoCreateUser->getKey())
        ->where('tenant_id', $this->atendimentoCreateTenant->getKey())
        ->update(['permissions_revoked' => json_encode(['criar_atendimento'], JSON_THROW_ON_ERROR)]);

    $this->postJson('/api/atendimentos', validAtendimentoCreatePayload())
        ->assertForbidden();
});

it('rejeita payload estruturalmente inválido antes de abrir a transação', function () {
    $this->postJson('/api/atendimentos', [
        'paciente_nome' => '',
        'paciente_cpf' => '',
        'idempotency_key' => 'nao-e-uuid',
        'exames' => [],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['paciente_nome', 'idempotency_key', 'exames']);

    $pdo = atendimentoCreateControlConnection($this->atendimentoCreateDatabase);
    expect((int) $pdo->query('SELECT count(*) FROM atendimentos')?->fetchColumn())->toBe(0);
});

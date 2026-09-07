<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentoDatabase = 'sislac_t_atd_api_'.Str::lower(Str::random(10));
    atendimentoApiControlConnection()->exec('CREATE DATABASE "'.$this->atendimentoDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Atendimento API',
        'code' => 'atd-api-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentoDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->atendimentoTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->atendimentoTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->atendimentoUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->atendimentoUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'recepcionista',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withHeader('X-Tenant', (string) $tenantId);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    atendimentoApiControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentoDatabase.'" WITH (FORCE)');
});

function atendimentoApiControlConnection(?string $database = null): PDO
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
function syntheticAtendimentoPayload(): array
{
    return [
        'atendimento' => [
            'paciente_id' => 123,
            'paciente_nome' => 'Paciente Sintético',
            'paciente_cpf' => '123.456.789-01',
            'paciente_nascimento' => '1990-09-07',
            'solicitante' => 'Solicitante Sintético',
            'convenio_id' => 0,
            'convenio_nome' => 'Particular',
            'unidade_id' => 'und-001',
            'idempotency_key' => (string) Str::uuid(),
            'jejum' => false,
            'prioridade_clinica' => 'normal',
            'origem_atendimento' => 'INTERNO',
        ],
        'exames' => [],
        'pagamentos' => [],
    ];
}

it('exige autenticação para criar atendimento', function () {
    $this->postJson('/api/atendimentos', syntheticAtendimentoPayload())
        ->assertUnauthorized();
});

it('nega criação sem criar_atendimento', function () {
    $this->actingAs($this->atendimentoUser, 'web');

    DB::connection('central')->table('memberships')
        ->where('user_id', $this->atendimentoUser->getKey())
        ->where('tenant_id', $this->atendimentoTenant->getKey())
        ->update([
            'role' => 'analista',
            'permissions_extra' => '[]',
            'permissions_revoked' => '[]',
        ]);

    $this->postJson('/api/atendimentos', syntheticAtendimentoPayload())
        ->assertForbidden();
});

it('valida o envelope e os itens obrigatórios do contrato', function () {
    $this->actingAs($this->atendimentoUser, 'web');

    $this->postJson('/api/atendimentos', [
        'atendimento' => [
            'paciente_nome' => '',
            'paciente_cpf' => 123,
        ],
        'exames' => [['nome_exame' => '']],
        'pagamentos' => [['tipo' => '', 'valor' => 'x']],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'atendimento.paciente_nome',
            'atendimento.paciente_cpf',
            'exames.0.nome_exame',
            'pagamentos.0.tipo',
            'pagamentos.0.valor',
        ]);
});

it('normaliza cpf e ignora campos derivados enviados pelo cliente', function () {
    $this->actingAs($this->atendimentoUser, 'web');

    $payload = syntheticAtendimentoPayload();
    $payload['atendimento']['protocolo'] = '9999999';
    $payload['atendimento']['status_atendimento'] = 'Finalizado';
    $payload['atendimento']['status_pagamento'] = 'Pago';
    $payload['atendimento']['senha_consulta'] = 'CLIENTE-NAO-PODE-DEFINIR';
    $payload['atendimento']['assinatura_protocolo'] = 'CLIENTE-NAO-PODE-DEFINIR';

    $response = $this->postJson('/api/atendimentos', $payload);

    $response->assertOk()
        ->assertJsonPath('ok', true);

    $pdo = atendimentoApiControlConnection($this->atendimentoDatabase);
    $row = $pdo->query('SELECT paciente_cpf, protocolo, status_atendimento, status_pagamento, senha_consulta, assinatura_protocolo FROM atendimentos ORDER BY id DESC LIMIT 1')?->fetch(PDO::FETCH_ASSOC);

    expect($row)->not->toBeFalse()
        ->and($row['paciente_cpf'])->toBe('12345678901')
        ->and($row['protocolo'])->not->toBe('9999999')
        ->and($row['status_atendimento'])->toBe('Pedido Realizado')
        ->and($row['status_pagamento'])->toBe('Pagamento pendente')
        ->and($row['senha_consulta'])->not->toBe('CLIENTE-NAO-PODE-DEFINIR')
        ->and($row['assinatura_protocolo'])->not->toBe('CLIENTE-NAO-PODE-DEFINIR');
});

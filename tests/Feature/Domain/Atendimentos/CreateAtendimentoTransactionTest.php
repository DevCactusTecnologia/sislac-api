<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->transactionDatabase = 'sislac_t_atd_tx_'.Str::lower(Str::random(10));
    atendimentoTransactionControlConnection()->exec('CREATE DATABASE "'.$this->transactionDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Atendimento Transação',
        'code' => 'atd-tx-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->transactionDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->transactionTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->transactionTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->transactionUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->transactionUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'recepcionista',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->actingAs($this->transactionUser, 'web');
    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withHeader('X-Tenant', (string) $tenantId);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    atendimentoTransactionControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->transactionDatabase.'" WITH (FORCE)');
});

function atendimentoTransactionControlConnection(?string $database = null): PDO
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
function atendimentoTransactionPayload(string $idempotencyKey): array
{
    return [
        'atendimento' => [
            'paciente_nome' => 'Paciente Sintético Transacional',
            'paciente_cpf' => '',
            'convenio_id' => 0,
            'convenio_nome' => 'Particular',
            'unidade_id' => 'und-001',
            'idempotency_key' => $idempotencyKey,
            'observacoes' => 'Observação legada compatível',
        ],
        'exames' => [
            [
                'nome_exame' => 'Hemograma Sintético',
                'valor' => 25.50,
                'ordem' => 1,
            ],
            [
                'nome_exame' => 'Exame Terceirizado Sintético',
                'valor' => 40,
                'valor_original' => 44,
                'ordem' => 2,
                'tipo_processo' => 'TERCEIRIZADO',
                'cobranca_destino' => 'convenio',
                'convenio_cobranca_id' => 9,
            ],
        ],
        'pagamentos' => [
            ['tipo' => 'PIX', 'valor' => 20],
            ['tipo' => 'Dinheiro', 'valor' => 5.50],
        ],
    ];
}

it('persiste atendimento exames e pagamentos como uma única criação compatível', function () {
    $response = $this->postJson(
        '/api/atendimentos',
        atendimentoTransactionPayload((string) Str::uuid()),
    );

    $response->assertOk()->assertJsonPath('ok', true);

    $pdo = atendimentoTransactionControlConnection($this->transactionDatabase);
    $atendimentoId = (int) $response->json('atendimento_id');

    $atendimento = $pdo->query('SELECT observacoes_assistente FROM atendimentos WHERE id = '.$atendimentoId)?->fetch(PDO::FETCH_ASSOC);
    $exames = $pdo->query('SELECT nome_exame, status, valor, valor_original, ordem, cobranca_destino, convenio_cobranca_id, amostra_seq, grupo_exame_id, tipo_processo FROM atendimento_exames WHERE atendimento_id = '.$atendimentoId.' ORDER BY ordem')?->fetchAll(PDO::FETCH_ASSOC);
    $pagamentos = $pdo->query('SELECT tipo, valor, data FROM atendimento_pagamentos WHERE atendimento_id = '.$atendimentoId.' ORDER BY id')?->fetchAll(PDO::FETCH_ASSOC);

    expect($atendimento)->not->toBeFalse()
        ->and($atendimento['observacoes_assistente'])->toBe('Observação legada compatível')
        ->and($exames)->toHaveCount(2)
        ->and($exames[0]['status'])->toBe('pendente')
        ->and($exames[0]['valor'])->toBe('25.50')
        ->and($exames[0]['valor_original'])->toBe('25.50')
        ->and($exames[0]['cobranca_destino'])->toBe('paciente')
        ->and($exames[0]['amostra_seq'])->toBe(1)
        ->and($exames[0]['grupo_exame_id'])->not->toBeEmpty()
        ->and($exames[0]['tipo_processo'])->toBe('INTERNO')
        ->and($exames[1]['valor_original'])->toBe('44.00')
        ->and($exames[1]['cobranca_destino'])->toBe('convenio')
        ->and($exames[1]['convenio_cobranca_id'])->toBe(9)
        ->and($exames[1]['tipo_processo'])->toBe('TERCEIRIZADO')
        ->and($pagamentos)->toHaveCount(2)
        ->and($pagamentos[0]['tipo'])->toBe('PIX')
        ->and($pagamentos[0]['valor'])->toBe('20.00')
        ->and($pagamentos[0]['data'])->not->toBeEmpty();
});

it('é idempotente em repetição sequencial da mesma chave', function () {
    $key = (string) Str::uuid();
    $payload = atendimentoTransactionPayload($key);

    $first = $this->postJson('/api/atendimentos', $payload)->assertOk();
    $second = $this->postJson('/api/atendimentos', $payload)->assertOk();

    $second->assertJsonPath('ok', true)
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('atendimento_id', $first->json('atendimento_id'))
        ->assertJsonPath('protocolo', $first->json('protocolo'));

    $pdo = atendimentoTransactionControlConnection($this->transactionDatabase);
    $count = (int) $pdo->query("SELECT count(*) FROM atendimentos WHERE idempotency_key = '{$key}'")?->fetchColumn();

    expect($count)->toBe(1);
});

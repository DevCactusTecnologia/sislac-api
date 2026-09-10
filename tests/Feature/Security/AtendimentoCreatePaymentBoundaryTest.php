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
    $this->database = 'sislac_t_fin_boundary_'.Str::lower(Str::random(8));
    financeiroBoundaryControl()->exec('CREATE DATABASE "'.$this->database.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Fronteira Financeira',
        'code' => 'fin-boundary-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->database,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->tenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->tenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->user = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->user->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'recepcionista',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => json_encode(['registrar_pagamento'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Http::preventStrayRequests();
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $this->user->id,
            'email' => $this->user->email,
        ], 200),
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withToken('valid-finance-boundary-token');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    financeiroBoundaryControl()->exec('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
});

function financeiroBoundaryControl(?string $database = null): PDO
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

it('não permite contornar registrar_pagamento pela criação de atendimento', function () {
    $idempotencyKey = (string) Str::uuid();

    $this->postJson('/api/atendimentos', [
        'paciente_nome' => 'Paciente Fronteira',
        'paciente_cpf' => '',
        'idempotency_key' => $idempotencyKey,
        'exames' => [[
            'nome_exame' => 'Glicose',
            'valor' => '50.00',
            'tipo_processo' => 'INTERNO',
            'amostra_seq' => 1,
        ]],
        'pagamentos' => [[
            'tipo' => 'PIX',
            'valor' => '50.00',
        ]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('pagamentos');

    $pdo = financeiroBoundaryControl($this->database);
    $statement = $pdo->prepare('SELECT count(*) FROM atendimentos WHERE idempotency_key = ?');
    $statement->execute([$idempotencyKey]);

    expect((int) $statement->fetchColumn())->toBe(0);
});

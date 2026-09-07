<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentoDatabase = 'sislac_t_atendimento_'.Str::lower(Str::random(10));
    atendimentoSchemaControlConnection()->exec('CREATE DATABASE "'.$this->atendimentoDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Atendimento Schema',
        'code' => 'atendimento-'.Str::lower(Str::random(8)),
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
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    atendimentoSchemaControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentoDatabase.'" WITH (FORCE)');
});

function atendimentoSchemaControlConnection(?string $database = null): PDO
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

it('cria as tres tabelas centrais do fluxo de atendimento', function () {
    expect(Schema::hasTable('atendimentos'))->toBeTrue()
        ->and(Schema::hasTable('atendimento_exames'))->toBeTrue()
        ->and(Schema::hasTable('atendimento_pagamentos'))->toBeTrue();
});

it('preserva defaults fisicos observados no Supabase', function () {
    $rows = DB::table('information_schema.columns')
        ->select(['table_name', 'column_name', 'column_default'])
        ->where('table_schema', 'public')
        ->whereIn('table_name', ['atendimentos', 'atendimento_exames', 'atendimento_pagamentos'])
        ->get();

    $defaults = [];

    foreach ($rows as $row) {
        $defaults[$row->table_name.'.'.$row->column_name] = $row->column_default;
    }

    expect((string) $defaults['atendimentos.status_atendimento'])->toContain('Pedido Realizado')
        ->and((string) $defaults['atendimentos.status_pagamento'])->toContain('Pagamento pendente')
        ->and((string) $defaults['atendimentos.origem_atendimento'])->toContain('INTERNO')
        ->and((string) $defaults['atendimentos.prioridade_clinica'])->toContain('normal')
        ->and((string) $defaults['atendimento_exames.status'])->toContain('pendente')
        ->and((string) $defaults['atendimento_exames.cobranca_destino'])->toContain('paciente')
        ->and((string) $defaults['atendimento_pagamentos.status_pagamento'])->toContain('efetuado');
});

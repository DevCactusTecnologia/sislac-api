<?php

use App\Platform\Authorization\TenantPermission;
use App\Platform\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rotinaDatabase = 'sislac_t_rotina_'.Str::lower(Str::random(10));
    rotinaSchemaControlConnection()->exec('CREATE DATABASE "'.$this->rotinaDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Rotina',
        'code' => 'rotina-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->rotinaDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->rotinaTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->rotinaTenant);

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
    rotinaSchemaControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->rotinaDatabase.'" WITH (FORCE)');
});

function rotinaSchemaControlConnection(?string $database = null): PDO
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

it('cria configuração singleton da rotina com modo completo por padrão', function () {
    expect(Schema::hasTable('lab_config'))->toBeTrue()
        ->and(DB::connection('tenant')->table('lab_config')->count())->toBe(1)
        ->and(DB::connection('tenant')->table('lab_config')->value('rotina_fluxo_modo'))->toBe('completo');
});

it('impede segundo registro de configuração no mesmo tenant', function () {
    DB::connection('tenant')->table('lab_config')->insert([
        'singleton_key' => 1,
        'rotina_fluxo_modo' => 'completo',
    ]);
})->throws(QueryException::class);

it('aceita somente os três modos canônicos da rotina', function () {
    DB::connection('tenant')->table('lab_config')->where('singleton_key', 1)->update([
        'rotina_fluxo_modo' => 'invalido',
    ]);
})->throws(QueryException::class);

it('expõe no backend as permissões já existentes do produto', function () {
    expect(TenantPermission::RegisterCollection->value)->toBe('registrar_coleta')
        ->and(TenantPermission::AnalyzeSample->value)->toBe('analisar_amostra')
        ->and(TenantPermission::SystemSettings->value)->toBe('configuracoes_sistema');
});

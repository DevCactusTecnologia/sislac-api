<?php

use App\Domain\Atendimentos\Support\AtendimentoProtocolo;
use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->protocoloDatabase = 'sislac_t_protocolo_'.Str::lower(Str::random(10));
    atendimentoProtocoloControlConnection()->exec('CREATE DATABASE "'.$this->protocoloDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Protocolo',
        'code' => 'protocolo-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->protocoloDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->protocoloTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->protocoloTenant);

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
    atendimentoProtocoloControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->protocoloDatabase.'" WITH (FORCE)');
});

function atendimentoProtocoloControlConnection(?string $database = null): PDO
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

it('gera protocolo no formato de sete digitos do sislacprivado', function () {
    $generator = app(AtendimentoProtocolo::class);

    expect($generator->next())->toBe('0000001')
        ->and($generator->next())->toBe('0000002');
});

it('mantem o contador atomico entre conexoes independentes', function () {
    $pdoA = atendimentoProtocoloControlConnection($this->protocoloDatabase);
    $pdoB = atendimentoProtocoloControlConnection($this->protocoloDatabase);

    $sql = <<<'SQL'
        INSERT INTO protocolo_sequence (prefixo, ano, ultimo_numero)
        VALUES ('ATD', 0, 1)
        ON CONFLICT (prefixo, ano)
        DO UPDATE SET ultimo_numero = protocolo_sequence.ultimo_numero + 1,
                      updated_at = now()
        RETURNING ultimo_numero
    SQL;

    $first = (int) $pdoA->query($sql)->fetchColumn();
    $second = (int) $pdoB->query($sql)->fetchColumn();

    expect([$first, $second])->toBe([1, 2]);
});

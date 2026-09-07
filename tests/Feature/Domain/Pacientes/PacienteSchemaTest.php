<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->pacientesDatabase = 'sislac_t_pacientes_'.Str::lower(Str::random(10));
    pacienteSchemaControlConnection()->exec('CREATE DATABASE "'.$this->pacientesDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Pacientes',
        'code' => 'pacientes-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->pacientesDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->pacientesTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->pacientesTenant);

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
    pacienteSchemaControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->pacientesDatabase.'" WITH (FORCE)');
});

function pacienteSchemaControlConnection(?string $database = null): PDO
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

it('cria o contrato físico da tabela pacientes no banco tenant', function () {
    expect(Schema::hasColumns('pacientes', [
        'id', 'nome', 'nome_social', 'cpf', 'data_nascimento', 'sexo', 'telefone', 'celular',
        'email', 'cep', 'estado', 'cidade', 'bairro', 'endereco', 'numero', 'complemento',
        'status', 'guardian_name', 'guardian_cpf', 'consentimento_lgpd', 'consentimento_em',
        'friendly_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('permite cpf vazio mas impede cpf não vazio duplicado', function () {
    $pdo = pacienteSchemaControlConnection($this->pacientesDatabase);

    $pdo->exec("INSERT INTO pacientes (nome, cpf) VALUES ('Paciente Sem CPF 1', NULL)");
    $pdo->exec("INSERT INTO pacientes (nome, cpf) VALUES ('Paciente Sem CPF 2', NULL)");
    $pdo->exec("INSERT INTO pacientes (nome, cpf) VALUES ('Paciente CPF', '12345678901')");

    expect(fn () => $pdo->exec(
        "INSERT INTO pacientes (nome, cpf) VALUES ('Paciente CPF Duplicado', '12345678901')",
    ))->toThrow(PDOException::class);
});

it('rejeita sexo fora do contrato', function () {
    $pdo = pacienteSchemaControlConnection($this->pacientesDatabase);

    expect(fn () => $pdo->exec(
        "INSERT INTO pacientes (nome, sexo) VALUES ('Paciente Sexo Inválido', 'X')",
    ))->toThrow(PDOException::class);
});

it('rejeita status fora do contrato', function () {
    $pdo = pacienteSchemaControlConnection($this->pacientesDatabase);

    expect(fn () => $pdo->exec(
        "INSERT INTO pacientes (nome, status) VALUES ('Paciente Status Inválido', 'Excluído')",
    ))->toThrow(PDOException::class);
});

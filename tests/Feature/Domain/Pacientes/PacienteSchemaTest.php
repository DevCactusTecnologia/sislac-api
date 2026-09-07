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

    $this->pacientesTenant = Tenant::query()->create([
        'name' => 'Laboratório Pacientes',
        'code' => 'pacientes-'.Str::lower(Str::random(8)),
    ]);
    $this->pacientesTenant->database_name = $this->pacientesDatabase;
    $this->pacientesTenant->status = 'active';
    $this->pacientesTenant->save();

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

function pacienteSchemaControlConnection(): PDO
{
    $config = config('database.connections.central');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
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
    DB::table('pacientes')->insert([
        ['nome' => 'Paciente Sem CPF 1', 'cpf' => null],
        ['nome' => 'Paciente Sem CPF 2', 'cpf' => null],
        ['nome' => 'Paciente CPF', 'cpf' => '12345678901'],
    ]);

    expect(fn () => DB::table('pacientes')->insert([
        'nome' => 'Paciente CPF Duplicado',
        'cpf' => '12345678901',
    ]))->toThrow(PDOException::class);
});

it('rejeita sexo e status fora do contrato', function () {
    expect(fn () => DB::table('pacientes')->insert([
        'nome' => 'Paciente Sexo Inválido',
        'sexo' => 'X',
    ]))->toThrow(PDOException::class);

    DB::rollBack();

    expect(fn () => DB::table('pacientes')->insert([
        'nome' => 'Paciente Status Inválido',
        'status' => 'Excluído',
    ]))->toThrow(PDOException::class);
});

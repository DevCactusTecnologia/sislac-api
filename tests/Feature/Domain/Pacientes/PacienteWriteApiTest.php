<?php

use App\Models\Laboratory;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->writeDatabase = 'sislac_t_write_'.Str::lower(Str::random(10));
    pacienteWriteControlConnection()->exec('CREATE DATABASE "'.$this->writeDatabase.'"');

    $laboratoryId = (string) Str::uuid();
    $now = now();

    createTestLaboratory([
        'id' => $laboratoryId,
        'name' => 'Laboratório Escrita',
        'code' => 'write-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->writeDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->writeLaboratory = Laboratory::query()->findOrFail($laboratoryId);
    connectTestLaboratory($this->writeLaboratory);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    disconnectTestLaboratory();

    $this->writeUser = User::factory()->create();
    assignTestLaboratoryUser([
        'user_id' => $this->writeUser->getKey(),
        'tenant_id' => $laboratoryId,
        'role' => 'recepcionista',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->actingAs($this->writeUser->fresh(), 'web');
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
    pacienteWriteControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->writeDatabase.'" WITH (FORCE)');
});

function pacienteWriteControlConnection(?string $database = null): PDO
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

it('cria paciente normalizando cpf data sexo e campos opcionais', function () {
    $response = $this->postJson('/api/pacientes', [
        'nome' => 'Maria da Silva',
        'nome_social' => '',
        'cpf' => '123.456.789-01',
        'data_nascimento' => '07/09/1990',
        'sexo' => 'Feminino',
        'telefone' => '(83) 3333-4444',
        'celular' => '',
        'email' => '',
        'guardian_name' => '',
        'guardian_cpf' => '',
        'consentimento_lgpd' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.nome', 'Maria da Silva')
        ->assertJsonPath('data.nome_social', null)
        ->assertJsonPath('data.cpf', '12345678901')
        ->assertJsonPath('data.data_nascimento', '1990-09-07')
        ->assertJsonPath('data.sexo', 'F')
        ->assertJsonPath('data.guardian_name', null)
        ->assertJsonPath('data.guardian_cpf', null)
        ->assertJsonPath('data.friendly_id', 'PAC-000001');

    $pdo = pacienteWriteControlConnection($this->writeDatabase);
    $row = $pdo->query("SELECT cpf, data_nascimento::text AS data_nascimento, sexo, nome_social, guardian_name, guardian_cpf, friendly_id FROM pacientes WHERE nome = 'Maria da Silva'")?->fetch(PDO::FETCH_ASSOC);

    expect($row)->toMatchArray([
        'cpf' => '12345678901',
        'data_nascimento' => '1990-09-07',
        'sexo' => 'F',
        'nome_social' => null,
        'guardian_name' => null,
        'guardian_cpf' => null,
        'friendly_id' => 'PAC-000001',
    ]);
});

it('traduz cpf duplicado para erro 422 estável', function () {
    $this->postJson('/api/pacientes', [
        'nome' => 'Primeiro',
        'cpf' => '123.456.789-01',
        'sexo' => 'Masculino',
    ])->assertCreated();

    $this->postJson('/api/pacientes', [
        'nome' => 'Segundo',
        'cpf' => '12345678901',
        'sexo' => 'Masculino',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.cpf.0', 'CPF já cadastrado para este laboratório.');
});

it('patch preserva campos ausentes e normaliza apenas os enviados', function () {
    $created = $this->postJson('/api/pacientes', [
        'nome' => 'Paciente Original',
        'cpf' => '111.222.333-44',
        'data_nascimento' => '01/01/2000',
        'sexo' => 'Masculino',
        'email' => 'original@example.test',
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->patchJson('/api/pacientes/'.$id, [
        'cpf' => '',
        'sexo' => 'Feminino',
        'status' => 'Inativo',
    ])->assertOk()
        ->assertJsonPath('data.nome', 'Paciente Original')
        ->assertJsonPath('data.email', 'original@example.test')
        ->assertJsonPath('data.cpf', null)
        ->assertJsonPath('data.sexo', 'F')
        ->assertJsonPath('data.status', 'Inativo');
});

it('ignora campos protegidos enviados pelo cliente', function () {
    $response = $this->postJson('/api/pacientes', [
        'id' => 999999,
        'friendly_id' => 'PAC-999999',
        'created_at' => '2000-01-01T00:00:00Z',
        'updated_at' => '2000-01-01T00:00:00Z',
        'nome' => 'Paciente Protegido',
        'cpf' => '555.666.777-88',
        'sexo' => 'Masculino',
    ])->assertCreated();

    expect($response->json('data.id'))->not->toBe(999999)
        ->and($response->json('data.friendly_id'))->toBe('PAC-000001')
        ->and($response->json('data.created_at'))->not->toStartWith('2000-01-01')
        ->and($response->json('data.updated_at'))->not->toStartWith('2000-01-01');

    $id = $response->json('data.id');

    $this->patchJson('/api/pacientes/'.$id, [
        'id' => 888888,
        'friendly_id' => 'PAC-888888',
        'created_at' => '2001-01-01T00:00:00Z',
        'updated_at' => '2001-01-01T00:00:00Z',
        'nome' => 'Paciente Protegido Editado',
    ])->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.friendly_id', 'PAC-000001');
});

it('aplica permissões distintas para criação e edição', function () {
    DB::connection('central')->table('users')
        ->where('id', $this->writeUser->getKey())
        ->where('laboratory_id', $this->writeLaboratory->getKey())
        ->update(['role' => 'analista']);
    $this->actingAs($this->writeUser->fresh(), 'web');

    $this->postJson('/api/pacientes', [
        'nome' => 'Sem Permissão',
        'sexo' => 'Masculino',
    ])->assertForbidden();

    $pdo = pacienteWriteControlConnection($this->writeDatabase);
    $pdo->exec("INSERT INTO pacientes (nome, sexo, friendly_id) VALUES ('Existente', 'M', 'PAC-000100')");
    $id = (int) $pdo->lastInsertId('pacientes_id_seq');

    $this->patchJson('/api/pacientes/'.$id, ['nome' => 'Não Pode Editar'])
        ->assertForbidden();
});

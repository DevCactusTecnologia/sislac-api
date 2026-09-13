<?php

use App\Domain\Pacientes\Actions\CreatePaciente;
use App\Domain\Pacientes\Models\Paciente;
use App\Domain\Pacientes\Services\PacienteFriendlyId;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->boundaryDatabases = [];
    $this->boundaryUsers = [];
    Http::preventStrayRequests();
    $this->withHeader('Origin', 'https://sislac.com.br');

    foreach (['A', 'B'] as $label) {
        $database = 'sislac_boundary_'.Str::lower(Str::random(12));
        testLaboratoryControlPdo()->exec('CREATE DATABASE "'.$database.'"');
        $this->boundaryDatabases[] = $database;
        $laboratory = createTestLaboratory([
            'name' => 'Laboratório '.$label,
            'code' => $database,
            'status' => 'active',
            'database_name' => $database,
        ]);
        connectTestLaboratory($laboratory);
        Artisan::call('migrate', [
            '--path' => database_path('migrations/tenant'),
            '--realpath' => true,
            '--force' => true,
        ]);
        DB::connection('lab')->table('pacientes')->insert([
            'nome' => 'Paciente '.$label,
            'friendly_id' => 'PAC-000001',
        ]);
        disconnectTestLaboratory();
        $this->boundaryUsers[$label] = User::factory()->create([
            'laboratory_id' => $laboratory->getKey(),
            'role' => 'admin',
            'status' => 'active',
            'is_super_admin' => false,
        ]);
    }
});

afterEach(function () {
    disconnectTestLaboratory();
    foreach ($this->boundaryDatabases as $database) {
        testLaboratoryControlPdo()->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
    }
});

it('alterna usuários A B A sem permitir seleção de banco por header ou query', function () {
    foreach (['A', 'B', 'A'] as $label) {
        $user = $this->boundaryUsers[$label];
        $other = $this->boundaryUsers[$label === 'A' ? 'B' : 'A'];
        $this->actingAs($user, 'web')
            ->withHeader('X-Tenant', $other->laboratory_id)
            ->getJson('/api/pacientes/1?laboratory_id='.$other->laboratory_id)
            ->assertOk()
            ->assertJsonPath('data.nome', 'Paciente '.$label);
        expect(config('database.connections.lab'))->toBeNull()
            ->and(DB::getDefaultConnection())->toBe('central');
    }
    Http::assertNothingSent();
});

it('bloqueia usuário inativo e super admin mesmo vinculados a laboratório ativo', function (array $attributes) {
    $user = $this->boundaryUsers['A'];
    $user->forceFill($attributes)->save();
    $this->actingAs($user, 'web')->getJson('/api/pacientes/1')->assertForbidden();
    expect(config('database.connections.lab'))->toBeNull();
})->with([
    'usuário suspenso' => [['status' => 'suspended']],
    'super admin' => [['is_super_admin' => true]],
]);

it('faz rollback do contador e do paciente na mesma conexão lab', function () {
    $laboratory = $this->boundaryUsers['A']->laboratory;
    connectTestLaboratory($laboratory);
    DB::connection('lab')->table('friendly_id_counters')->where('scope', 'paciente')->delete();
    DB::connection('lab')->table('pacientes')->delete();
    DB::setDefaultConnection('central');

    expect(function () {
        DB::connection('lab')->transaction(function () {
            app(CreatePaciente::class)->execute(['nome' => 'Paciente rollback'], app(PacienteFriendlyId::class));
            throw new RuntimeException('rollback esperado');
        });
    })->toThrow(RuntimeException::class, 'rollback esperado');

    expect(Paciente::query()->count())->toBe(0)
        ->and(DB::connection('lab')->table('friendly_id_counters')->count())->toBe(0);
});

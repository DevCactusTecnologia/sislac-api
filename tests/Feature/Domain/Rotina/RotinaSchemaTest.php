<?php

use App\Platform\Authorization\TenantPermission;
use App\Models\Laboratory;
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

    $laboratoryId = (string) Str::uuid();
    $now = now();

    createTestLaboratory([
        'id' => $laboratoryId,
        'name' => 'Laboratório Rotina',
        'code' => 'rotina-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->rotinaDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->rotinaLaboratory = Laboratory::query()->findOrFail($laboratoryId);
    connectTestLaboratory($this->rotinaLaboratory);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
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

function rotinaSetMode(string $mode): void
{
    DB::connection('lab')->table('lab_config')->where('singleton_key', 1)->update([
        'rotina_fluxo_modo' => $mode,
    ]);
}

function rotinaCreateAtendimento(): int
{
    return (int) DB::connection('lab')->table('atendimentos')->insertGetId([
        'paciente_nome' => 'Paciente Rotina',
        'paciente_cpf' => '',
    ]);
}

/** @param array<string, mixed> $attributes */
function rotinaCreateExame(int $atendimentoId, array $attributes = []): int
{
    return (int) DB::connection('lab')->table('atendimento_exames')->insertGetId(array_merge([
        'atendimento_id' => $atendimentoId,
        'nome_exame' => 'Hemograma '.Str::lower(Str::random(6)),
        'status' => 'pendente',
        'tipo_processo' => 'INTERNO',
    ], $attributes));
}

it('cria configuração singleton da rotina com modo completo por padrão', function () {
    expect(Schema::hasTable('lab_config'))->toBeTrue()
        ->and(DB::connection('lab')->table('lab_config')->count())->toBe(1)
        ->and(DB::connection('lab')->table('lab_config')->value('rotina_fluxo_modo'))->toBe('completo');
});

it('impede segundo registro de configuração no mesmo tenant', function () {
    DB::connection('lab')->table('lab_config')->insert([
        'singleton_key' => 1,
        'rotina_fluxo_modo' => 'completo',
    ]);
})->throws(QueryException::class);

it('aceita somente os três modos canônicos da rotina', function () {
    DB::connection('lab')->table('lab_config')->where('singleton_key', 1)->update([
        'rotina_fluxo_modo' => 'invalido',
    ]);
})->throws(QueryException::class);

it('expõe no backend as permissões já existentes do produto', function () {
    expect(TenantPermission::RegisterCollection->value)->toBe('registrar_coleta')
        ->and(TenantPermission::AnalyzeSample->value)->toBe('analisar_amostra')
        ->and(TenantPermission::SystemSettings->value)->toBe('configuracoes_sistema');
});

it('bloqueia salto direto de pendente para analisado no modo completo', function () {
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->update([
        'status' => 'analisado',
    ]);
})->throws(QueryException::class);

it('permite a sequência operacional completa', function () {
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    foreach (['coletado', 'em_bancada', 'analisado'] as $status) {
        DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->update([
            'status' => $status,
        ]);
    }

    expect(DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->value('status'))
        ->toBe('analisado');
});

it('encurta coleta para analisado no modo coleta resultado', function () {
    rotinaSetMode('coleta_resultado');
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->update([
        'status' => 'coletado',
    ]);

    $exame = DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->first();

    expect($exame?->status)->toBe('analisado')
        ->and($exame?->data_coleta)->not->toBeNull()
        ->and($exame?->data_analise)->not->toBeNull()
        ->and($exame?->analista)->toBe('__SEM_REGISTRO__');
});

it('não materializa bancada no modo coleta resultado', function () {
    rotinaSetMode('coleta_resultado');
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->update([
        'status' => 'em_bancada',
    ]);
})->throws(QueryException::class);

it('short circuita novo exame interno no modo apenas resultado', function () {
    rotinaSetMode('apenas_resultado');
    $exameId = rotinaCreateExame(rotinaCreateAtendimento());

    $exame = DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->first();

    expect($exame?->status)->toBe('analisado')
        ->and($exame?->data_coleta)->not->toBeNull()
        ->and($exame?->data_analise)->not->toBeNull()
        ->and($exame?->coletor)->toBe('__SEM_REGISTRO__')
        ->and($exame?->analista)->toBe('__SEM_REGISTRO__');
});

it('preserva exame terceirizado digitado nos modos encurtados', function () {
    rotinaSetMode('apenas_resultado');
    $exameId = rotinaCreateExame(rotinaCreateAtendimento(), [
        'status' => 'digitado',
        'tipo_processo' => 'TERCEIRIZADO',
    ]);

    $exame = DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->first();

    expect($exame?->status)->toBe('digitado')
        ->and($exame?->data_coleta)->toBeNull()
        ->and($exame?->data_analise)->toBeNull();
});

it('impede regressão de exame finalizado sem retificação futura', function () {
    $exameId = rotinaCreateExame(rotinaCreateAtendimento(), [
        'status' => 'finalizado',
    ]);

    DB::connection('lab')->table('atendimento_exames')->where('id', $exameId)->update([
        'status' => 'pendente',
    ]);
})->throws(QueryException::class);

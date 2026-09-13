<?php

use App\Domain\Atendimentos\Actions\UpdateAtendimento;
use App\Models\Laboratory;
use App\Platform\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentoUpdateDatabase = 'sislac_t_atupdate_'.Str::lower(Str::random(9));
    atendimentoUpdateControlConnection()->exec('CREATE DATABASE "'.$this->atendimentoUpdateDatabase.'"');

    $laboratoryId = (string) Str::uuid();
    $now = now();

    createTestLaboratory([
        'id' => $laboratoryId,
        'name' => 'Laboratório Edição Atendimentos',
        'code' => 'atupdate-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentoUpdateDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->atendimentoUpdateLaboratory = Laboratory::query()->findOrFail($laboratoryId);
    connectTestLaboratory($this->atendimentoUpdateLaboratory);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    disconnectTestLaboratory();

    $this->atendimentoUpdateUser = User::factory()->create();
    assignTestLaboratoryUser([
        'user_id' => $this->atendimentoUpdateUser->getKey(),
        'tenant_id' => $laboratoryId,
        'role' => 'recepcionista',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Http::preventStrayRequests();
    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->actingAs($this->atendimentoUpdateUser->fresh(), 'web');
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
    atendimentoUpdateControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentoUpdateDatabase.'" WITH (FORCE)');
});

function atendimentoUpdateControlConnection(?string $database = null): PDO
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
function atendimentoUpdateCreatePayload(array $exames = []): array
{
    return [
        'paciente_nome' => 'Paciente Edição',
        'paciente_cpf' => '12345678901',
        'solicitante' => 'Solicitante Original',
        'unidade_id' => 'und-001',
        'idempotency_key' => (string) Str::uuid(),
        'exames' => $exames === [] ? [[
            'nome_exame' => 'Hemograma',
            'valor' => '100.00',
            'ordem' => 1,
            'tipo_processo' => 'INTERNO',
            'amostra_seq' => 1,
        ]] : $exames,
    ];
}

function atendimentoUpdateAdvanceExamesToFinalizado(PDO $pdo, int $atendimentoId): void
{
    $statement = $pdo->prepare('UPDATE atendimento_exames SET status = ? WHERE atendimento_id = ?');

    foreach (['coletado', 'em_bancada', 'analisado', 'finalizado'] as $status) {
        $statement->execute([$status, $atendimentoId]);
    }
}

it('edita escalares e ignora protocolo e status derivados enviados pelo cliente', function () {
    $created = $this->postJson('/api/atendimentos', atendimentoUpdateCreatePayload())->assertCreated();
    $id = (int) $created->json('atendimento_id');
    $protocol = (string) $created->json('protocolo');

    $this->patchJson('/api/atendimentos/'.$id, [
        'solicitante' => 'Solicitante Atualizado',
        'observacoes_assistente' => 'Observação atualizada',
        'protocolo' => '9999999',
        'status_atendimento' => 'Resultado Liberado',
        'status_pagamento' => 'Pagamento efetuado',
    ])->assertOk()
        ->assertJsonPath('data.solicitante', 'Solicitante Atualizado')
        ->assertJsonPath('data.protocolo', $protocol);

    $pdo = atendimentoUpdateControlConnection($this->atendimentoUpdateDatabase);
    $row = $pdo->query("SELECT protocolo, status_atendimento, status_pagamento, subtotal::text, total::text FROM atendimentos WHERE id = {$id}")?->fetch(PDO::FETCH_ASSOC);

    expect($row)->toMatchArray([
        'protocolo' => $protocol,
        'status_atendimento' => 'Pedido Realizado',
        'status_pagamento' => 'Pagamento pendente',
        'subtotal' => '100.00',
        'total' => '100.00',
    ]);
});

it('preserva estado clínico ordem e valor original da mesma ocorrência de exame', function () {
    $created = $this->postJson('/api/atendimentos', atendimentoUpdateCreatePayload([[
        'nome_exame' => 'Hemograma',
        'valor' => '100.00',
        'valor_original' => '120.00',
        'ordem' => 1,
        'tipo_processo' => 'INTERNO',
        'amostra_seq' => 1,
    ]]))->assertCreated();
    $id = (int) $created->json('atendimento_id');
    $pdo = atendimentoUpdateControlConnection($this->atendimentoUpdateDatabase);

    atendimentoUpdateAdvanceExamesToFinalizado($pdo, $id);
    $pdo->exec("UPDATE atendimento_exames SET ordem = 7, resultados = '{\"hb\":\"13.5\"}'::jsonb WHERE atendimento_id = {$id}");

    $this->patchJson('/api/atendimentos/'.$id, [
        'exames' => [[
            'nome_exame' => '  HEMOGRAMA  ',
            'valor' => '90.00',
            'valor_original' => '90.00',
            'ordem' => 1,
            'tipo_processo' => 'INTERNO',
            'amostra_seq' => 1,
            'status' => 'pendente',
        ]],
    ])->assertOk();

    $row = $pdo->query("SELECT nome_exame, status, ordem, valor::text, valor_original::text, resultados::text FROM atendimento_exames WHERE atendimento_id = {$id}")?->fetch(PDO::FETCH_ASSOC);

    expect($row)->toMatchArray([
        'nome_exame' => 'HEMOGRAMA',
        'status' => 'finalizado',
        'ordem' => 7,
        'valor' => '90.00',
        'valor_original' => '120.00',
        'resultados' => '{"hb": "13.5"}',
    ]);
});

it('nova amostra da mesma identidade não herda estado clínico da ocorrência anterior', function () {
    $created = $this->postJson('/api/atendimentos', atendimentoUpdateCreatePayload([[
        'nome_exame' => 'Hemograma',
        'valor' => '100.00',
        'valor_original' => '120.00',
        'ordem' => 1,
        'tipo_processo' => 'INTERNO',
        'amostra_seq' => 1,
    ]]))->assertCreated();
    $id = (int) $created->json('atendimento_id');
    $pdo = atendimentoUpdateControlConnection($this->atendimentoUpdateDatabase);

    atendimentoUpdateAdvanceExamesToFinalizado($pdo, $id);
    $pdo->exec("UPDATE atendimento_exames SET ordem = 7 WHERE atendimento_id = {$id}");

    $this->patchJson('/api/atendimentos/'.$id, [
        'exames' => [
            [
                'nome_exame' => 'Hemograma',
                'valor' => '90.00',
                'tipo_processo' => 'INTERNO',
                'amostra_seq' => 1,
            ],
            [
                'nome_exame' => 'Hemograma',
                'valor' => '80.00',
                'ordem' => 9,
                'tipo_processo' => 'INTERNO',
                'amostra_seq' => 2,
            ],
        ],
    ])->assertOk();

    $rows = $pdo->query("SELECT amostra_seq, status, ordem, valor_original::text FROM atendimento_exames WHERE atendimento_id = {$id} ORDER BY amostra_seq")?->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toBe([
        ['amostra_seq' => 1, 'status' => 'finalizado', 'ordem' => 7, 'valor_original' => '120.00'],
        ['amostra_seq' => 2, 'status' => 'pendente', 'ordem' => 9, 'valor_original' => '80.00'],
    ]);
});

it('substitui lista de exames atomicamente e faz rollback integral se novo filho viola o banco', function () {
    $created = $this->postJson('/api/atendimentos', atendimentoUpdateCreatePayload([
        ['nome_exame' => 'Hemograma', 'valor' => '100.00', 'ordem' => 1, 'tipo_processo' => 'INTERNO', 'amostra_seq' => 1],
        ['nome_exame' => 'Glicose', 'valor' => '20.00', 'ordem' => 2, 'tipo_processo' => 'INTERNO', 'amostra_seq' => 1],
    ]))->assertCreated();
    $id = (int) $created->json('atendimento_id');

    connectTestLaboratory($this->atendimentoUpdateLaboratory);

    expect(fn () => app(UpdateAtendimento::class)->handle($id, [
        'solicitante' => 'Não Deve Persistir',
        'exames' => [
            ['nome_exame' => 'Hemograma', 'valor' => '90.00', 'tipo_processo' => 'INTERNO', 'amostra_seq' => 1],
            ['nome_exame' => 'Novo Inválido', 'valor' => '30.00', 'tipo_processo' => 'INVALIDO', 'amostra_seq' => 1],
        ],
    ], null))->toThrow(QueryException::class);

    disconnectTestLaboratory();

    $pdo = atendimentoUpdateControlConnection($this->atendimentoUpdateDatabase);
    expect((string) $pdo->query("SELECT solicitante FROM atendimentos WHERE id = {$id}")?->fetchColumn())->toBe('Solicitante Original')
        ->and((int) $pdo->query("SELECT count(*) FROM atendimento_exames WHERE atendimento_id = {$id}")?->fetchColumn())->toBe(2)
        ->and((string) $pdo->query("SELECT valor::text FROM atendimento_exames WHERE atendimento_id = {$id} AND nome_exame = 'Hemograma'")?->fetchColumn())->toBe('100.00');
});

it('cancela sem exclusão física marcando todos os exames e registrando justificativa na auditoria', function () {
    $created = $this->postJson('/api/atendimentos', atendimentoUpdateCreatePayload([
        ['nome_exame' => 'Hemograma', 'valor' => '100.00', 'tipo_processo' => 'INTERNO', 'amostra_seq' => 1],
        ['nome_exame' => 'Glicose', 'valor' => '20.00', 'tipo_processo' => 'INTERNO', 'amostra_seq' => 1],
    ]))->assertCreated();
    $id = (int) $created->json('atendimento_id');

    $this->patchJson('/api/atendimentos/'.$id, [
        'cancelar' => true,
        'motivo_cancelamento' => 'Atendimento duplicado',
        'justificativa' => 'Cancelamento confirmado na recepção',
    ])->assertOk()
        ->assertJsonPath('data.status_atendimento', 'Cancelado')
        ->assertJsonPath('data.motivo_cancelamento', 'Atendimento duplicado');

    $pdo = atendimentoUpdateControlConnection($this->atendimentoUpdateDatabase);
    expect((int) $pdo->query("SELECT count(*) FROM atendimentos WHERE id = {$id}")?->fetchColumn())->toBe(1)
        ->and((int) $pdo->query("SELECT count(*) FROM atendimento_exames WHERE atendimento_id = {$id} AND status = 'cancelado'")?->fetchColumn())->toBe(2)
        ->and((string) $pdo->query("SELECT justificativa FROM atendimento_audit WHERE atendimento_id = {$id} AND justificativa <> '' ORDER BY id DESC LIMIT 1")?->fetchColumn())->toBe('Cancelamento confirmado na recepção');
});

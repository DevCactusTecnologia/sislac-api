<?php

use App\Models\Laboratory;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->atendimentoReadDatabase = 'sislac_t_atread_'.Str::lower(Str::random(10));
    atendimentoReadControlConnection()->exec('CREATE DATABASE "'.$this->atendimentoReadDatabase.'"');

    $laboratoryId = (string) Str::uuid();
    $now = now();

    createTestLaboratory([
        'id' => $laboratoryId,
        'name' => 'Laboratório Leitura Atendimentos',
        'code' => 'atread-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->atendimentoReadDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $laboratory = Laboratory::query()->findOrFail($laboratoryId);
    connectTestLaboratory($laboratory);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    disconnectTestLaboratory();

    $this->atendimentoReadUser = User::factory()->create();
    assignTestLaboratoryUser([
        'user_id' => $this->atendimentoReadUser->getKey(),
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
    $this->actingAs($this->atendimentoReadUser->fresh(), 'web');
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
    atendimentoReadControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->atendimentoReadDatabase.'" WITH (FORCE)');
});

function atendimentoReadControlConnection(?string $database = null): PDO
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

/** @return array{id:int,protocolo:string} */
function seedAtendimentoReadRow(string $database, int $index, array $overrides = []): array
{
    $pdo = atendimentoReadControlConnection($database);
    $data = array_merge([
        'data' => sprintf('2026-09-%02d 12:00:00+00', (($index - 1) % 7) + 1),
        'paciente_nome' => sprintf('Paciente Atendimento %03d', $index),
        'paciente_cpf' => sprintf('%011d', 20000000000 + $index),
        'solicitante' => 'Dr. Solicitante',
        'convenio_nome' => 'Particular',
        'unidade_id' => 'und-001',
    ], $overrides);

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimentos (
            data, paciente_nome, paciente_cpf, solicitante, convenio_nome, unidade_id,
            status_atendimento, status_pagamento
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id, protocolo
    SQL);
    $statement->execute([
        $data['data'],
        $data['paciente_nome'],
        $data['paciente_cpf'],
        $data['solicitante'],
        $data['convenio_nome'],
        $data['unidade_id'],
        $data['status_atendimento'] ?? 'Pedido Realizado',
        $data['status_pagamento'] ?? 'Pagamento pendente',
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return ['id' => (int) $row['id'], 'protocolo' => (string) $row['protocolo']];
}

it('pagina por cursor composto data e id sem repetir registros', function () {
    for ($index = 1; $index <= 55; $index++) {
        seedAtendimentoReadRow($this->atendimentoReadDatabase, $index);
    }

    $first = $this->getJson('/api/atendimentos')
        ->assertOk()
        ->assertJsonCount(50, 'data');

    $cursor = $first->json('meta.nextCursor');
    expect($cursor)->toBeString()->not->toBe('');

    $firstIds = collect($first->json('data'))->pluck('id')->all();
    $second = $this->getJson('/api/atendimentos?cursor='.urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonCount(5, 'data');

    expect(array_intersect($firstIds, collect($second->json('data'))->pluck('id')->all()))->toBe([])
        ->and($second->json('meta.nextCursor'))->toBeNull();
});

it('rejeita cursor estruturalmente inválido', function () {
    $this->getJson('/api/atendimentos?cursor=invalido')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
});

it('aplica filtros de status pagamento unidade e período inclusive', function () {
    seedAtendimentoReadRow($this->atendimentoReadDatabase, 1, [
        'data' => '2026-09-01 12:00:00+00',
        'status_atendimento' => 'Pedido Realizado',
        'status_pagamento' => 'Pagamento pendente',
        'unidade_id' => 'und-a',
    ]);
    seedAtendimentoReadRow($this->atendimentoReadDatabase, 2, [
        'data' => '2026-09-02 12:00:00+00',
        'paciente_nome' => 'Alvo do Período',
        'status_atendimento' => 'Resultado Liberado',
        'status_pagamento' => 'Pagamento efetuado',
        'unidade_id' => 'und-b',
    ]);
    seedAtendimentoReadRow($this->atendimentoReadDatabase, 3, [
        'data' => '2026-09-03 12:00:00+00',
        'status_atendimento' => 'Resultado Liberado',
        'status_pagamento' => 'Pagamento efetuado',
        'unidade_id' => 'und-b',
    ]);

    $this->getJson('/api/atendimentos?status=Resultado%20Liberado&pagamento=Pagamento%20efetuado&unidade_id=und-b&data_inicio=2026-09-02&data_fim=2026-09-02')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.paciente_nome', 'Alvo do Período');
});

it('busca por nome cpf protocolo solicitante e convênio', function () {
    $target = seedAtendimentoReadRow($this->atendimentoReadDatabase, 10, [
        'paciente_nome' => 'Maria Busca',
        'paciente_cpf' => '12345678901',
        'solicitante' => 'Dra. Helena',
        'convenio_nome' => 'Convênio Horizonte',
    ]);
    seedAtendimentoReadRow($this->atendimentoReadDatabase, 11, ['paciente_nome' => 'Outro Paciente']);

    foreach (['Maria Busca', '456789', $target['protocolo'], 'Helena', 'Horizonte'] as $query) {
        $this->getJson('/api/atendimentos?q='.urlencode($query))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target['id']);
    }
});

it('carrega detalhe por id e por protocolo com exames e pagamentos', function () {
    $target = seedAtendimentoReadRow($this->atendimentoReadDatabase, 20);
    $pdo = atendimentoReadControlConnection($this->atendimentoReadDatabase);
    $pdo->exec(sprintf(
        "INSERT INTO atendimento_exames (atendimento_id, nome_exame, valor, valor_original, ordem) VALUES (%d, 'Hemograma', 80, 100, 1)",
        $target['id'],
    ));
    $pdo->exec(sprintf(
        "INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor) VALUES (%d, 'PIX', 30)",
        $target['id'],
    ));

    foreach ([
        '/api/atendimentos/'.$target['id'],
        '/api/atendimentos/protocolo/'.$target['protocolo'],
    ] as $url) {
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.id', $target['id'])
            ->assertJsonPath('data.protocolo', $target['protocolo'])
            ->assertJsonPath('data.exames.0.nome_exame', 'Hemograma')
            ->assertJsonPath('data.pagamentos.0.tipo', 'PIX');
    }
});

it('retorna 404 para id e protocolo inexistentes', function () {
    $this->getJson('/api/atendimentos/999999')->assertNotFound();
    $this->getJson('/api/atendimentos/protocolo/9999999')->assertNotFound();
});

it('calcula kpis do universo filtrado e receita de exames ativos', function () {
    $pdo = atendimentoReadControlConnection($this->atendimentoReadDatabase);

    $pending = seedAtendimentoReadRow($this->atendimentoReadDatabase, 31);
    $collected = seedAtendimentoReadRow($this->atendimentoReadDatabase, 32);
    $final = seedAtendimentoReadRow($this->atendimentoReadDatabase, 33);
    $cancelled = seedAtendimentoReadRow($this->atendimentoReadDatabase, 34);

    foreach ([
        [$pending['id'], 'Exame Pendente', 'pendente', 100],
        [$collected['id'], 'Exame Coletado', 'coletado', 200],
        [$final['id'], 'Exame Finalizado', 'finalizado', 300],
        [$cancelled['id'], 'Exame Cancelado', 'cancelado', 400],
    ] as [$id, $nome, $status, $valor]) {
        $statement = $pdo->prepare(
            'INSERT INTO atendimento_exames (atendimento_id, nome_exame, status, valor, valor_original, ordem) VALUES (?, ?, ?, ?, ?, 1)',
        );
        $statement->execute([$id, $nome, $status, $valor, $valor]);
    }

    $this->getJson('/api/atendimentos/kpis')
        ->assertOk()
        ->assertJsonPath('total', 4)
        ->assertJsonPath('aguardando_coleta', 1)
        ->assertJsonPath('em_analise', 1)
        ->assertJsonPath('pendentes', 2)
        ->assertJsonPath('finalizados', 1)
        ->assertJsonPath('receita_total', '600.00');
});

it('limita page_size entre 10 e 200', function () {
    for ($index = 1; $index <= 205; $index++) {
        seedAtendimentoReadRow($this->atendimentoReadDatabase, $index);
    }

    $this->getJson('/api/atendimentos?page_size=1')
        ->assertOk()
        ->assertJsonCount(10, 'data');

    $this->getJson('/api/atendimentos?page_size=999')
        ->assertOk()
        ->assertJsonCount(200, 'data');
});

it('prova que o planner pode usar o índice composto da paginação', function () {
    for ($index = 1; $index <= 200; $index++) {
        seedAtendimentoReadRow($this->atendimentoReadDatabase, $index);
    }

    $pdo = atendimentoReadControlConnection($this->atendimentoReadDatabase);
    $pdo->exec('SET enable_seqscan = off');

    try {
        $plan = $pdo->query(<<<'SQL'
            EXPLAIN (FORMAT JSON)
            SELECT id, data
            FROM atendimentos
            ORDER BY data DESC, id DESC
            LIMIT 51
        SQL)->fetchColumn();
    } finally {
        $pdo->exec('RESET enable_seqscan');
    }

    expect($plan)->toBeString()
        ->and($plan)->toContain('idx_atendimentos_cursor');
});

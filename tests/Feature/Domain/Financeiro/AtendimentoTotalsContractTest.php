<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->totaisDatabase = 'sislac_t_totais_'.Str::lower(Str::random(9));
    totaisControlConnection()->exec('CREATE DATABASE "'.$this->totaisDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Totais Canônicos',
        'code' => 'totais-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->totaisDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->totaisTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->totaisTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->totaisUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->totaisUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'recepcionista',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Http::preventStrayRequests();
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $this->totaisUser->id,
            'email' => $this->totaisUser->email,
        ], 200),
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withToken('valid-totais-token');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    totaisControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->totaisDatabase.'" WITH (FORCE)');
});

function totaisControlConnection(?string $database = null): PDO
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

function totaisCreateAtendimento(PDO $pdo): int
{
    $statement = $pdo->query(<<<'SQL'
        INSERT INTO atendimentos (paciente_nome, paciente_cpf)
        VALUES ('Paciente Totais', '')
        RETURNING id
    SQL);

    return (int) $statement?->fetchColumn();
}

function totaisInsertExame(
    PDO $pdo,
    int $atendimentoId,
    string $valor,
    string $valorOriginal,
    string $status = 'pendente',
): int {
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_exames
            (atendimento_id, nome_exame, valor, valor_original, status, tipo_processo, amostra_seq, cobranca_destino)
        VALUES (?, 'Exame Totais', ?, ?, ?, 'INTERNO', 1, 'paciente')
        RETURNING id
    SQL);
    $statement->execute([$atendimentoId, $valor, $valorOriginal, $status]);

    return (int) $statement->fetchColumn();
}

/** @return array{subtotal:string,desconto_total:string,acrescimo_total:string,total:string} */
function totaisReadParent(PDO $pdo, int $atendimentoId): array
{
    $statement = $pdo->query(sprintf(
        'SELECT subtotal::text, desconto_total::text, acrescimo_total::text, total::text FROM atendimentos WHERE id = %d',
        $atendimentoId,
    ));
    $row = $statement?->fetch(PDO::FETCH_ASSOC);

    if (! is_array($row)) {
        throw new RuntimeException('Atendimento de teste não encontrado.');
    }

    return [
        'subtotal' => (string) $row['subtotal'],
        'desconto_total' => (string) $row['desconto_total'],
        'acrescimo_total' => (string) $row['acrescimo_total'],
        'total' => (string) $row['total'],
    ];
}

it('deriva desconto a partir de valor_original e valor sem cálculo no cliente', function () {
    $pdo = totaisControlConnection($this->totaisDatabase);
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '100.00',
        'desconto_total' => '20.00',
        'acrescimo_total' => '0.00',
        'total' => '80.00',
    ]);
});

it('deriva acréscimo a partir de valor_original e valor sem cálculo paralelo', function () {
    $pdo = totaisControlConnection($this->totaisDatabase);
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '120.00', '100.00');

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '100.00',
        'desconto_total' => '0.00',
        'acrescimo_total' => '20.00',
        'total' => '120.00',
    ]);
});

it('mantém desconto e acréscimo líquidos quando ajustes opostos se compensam', function () {
    $pdo = totaisControlConnection($this->totaisDatabase);
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '90.00', '100.00');
    totaisInsertExame($pdo, $atendimentoId, '60.00', '50.00');

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '150.00',
        'desconto_total' => '0.00',
        'acrescimo_total' => '0.00',
        'total' => '150.00',
    ]);
});

it('exclui exame cancelado do subtotal e do total', function () {
    $pdo = totaisControlConnection($this->totaisDatabase);
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');
    totaisInsertExame($pdo, $atendimentoId, '999.00', '999.00', 'cancelado');

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '100.00',
        'desconto_total' => '20.00',
        'acrescimo_total' => '0.00',
        'total' => '80.00',
    ]);
});

it('preserva valor_original já definido em alteração direta no banco', function () {
    $pdo = totaisControlConnection($this->totaisDatabase);
    $atendimentoId = totaisCreateAtendimento($pdo);
    $exameId = totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');

    $update = $pdo->prepare('UPDATE atendimento_exames SET valor_original = ? WHERE id = ?');
    $update->execute(['500.00', $exameId]);

    $statement = $pdo->prepare('SELECT valor_original::text FROM atendimento_exames WHERE id = ?');
    $statement->execute([$exameId]);

    expect((string) $statement->fetchColumn())->toBe('100.00');
});

it('recusa totais derivados enviados na criação do atendimento', function () {
    $this->postJson('/api/atendimentos', [
        'paciente_nome' => 'Paciente HTTP Totais',
        'paciente_cpf' => '',
        'exames' => [[
            'nome_exame' => 'Hemograma',
            'valor' => '80.00',
            'valor_original' => '100.00',
            'tipo_processo' => 'INTERNO',
            'amostra_seq' => 1,
        ]],
        'subtotal' => '1.00',
        'desconto_total' => '1.00',
        'acrescimo_total' => '1.00',
        'total' => '1.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['subtotal', 'desconto_total', 'acrescimo_total', 'total']);
});

it('recusa totais derivados enviados na atualização do atendimento', function () {
    $pdo = totaisControlConnection($this->totaisDatabase);
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');

    $this->patchJson('/api/atendimentos/'.$atendimentoId, [
        'subtotal' => '1.00',
        'desconto_total' => '1.00',
        'acrescimo_total' => '1.00',
        'total' => '1.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['subtotal', 'desconto_total', 'acrescimo_total', 'total']);

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '100.00',
        'desconto_total' => '20.00',
        'acrescimo_total' => '0.00',
        'total' => '80.00',
    ]);
});

it('expõe no recurso somente os totais persistidos pelo backend', function () {
    $pdo = totaisControlConnection($this->totaisDatabase);
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');

    $this->getJson('/api/atendimentos/'.$atendimentoId)
        ->assertOk()
        ->assertJsonPath('data.subtotal', '100.00')
        ->assertJsonPath('data.desconto_total', '20.00')
        ->assertJsonPath('data.acrescimo_total', '0.00')
        ->assertJsonPath('data.total', '80.00');
});

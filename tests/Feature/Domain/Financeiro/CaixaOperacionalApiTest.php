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
    $this->caixaDatabase = 'sislac_t_caixa_'.Str::lower(Str::random(9));
    caixaOpControlConnection()->exec('CREATE DATABASE "'.$this->caixaDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Caixa Operacional',
        'code' => 'caixa-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->caixaDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->caixaTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->caixaTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->caixaUser = User::factory()->create();
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->caixaUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'financeiro',
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
            'id' => $this->caixaUser->id,
            'email' => $this->caixaUser->email,
        ], 200),
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withToken('valid-caixa-token');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    caixaOpControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->caixaDatabase.'" WITH (FORCE)');
});

function caixaOpControlConnection(?string $database = null): PDO
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

function caixaOpCreateAtendimento(PDO $pdo, string $unidadeId, string $valor = '500.00'): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimentos (paciente_nome, paciente_cpf, unidade_id)
        VALUES ('Paciente Caixa', '', ?)
        RETURNING id
    SQL);
    $statement->execute([$unidadeId]);
    $atendimentoId = (int) $statement->fetchColumn();

    $exam = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_exames
            (atendimento_id, nome_exame, valor, valor_original, status, tipo_processo, amostra_seq, cobranca_destino)
        VALUES (?, 'Exame Caixa', ?, ?, 'pendente', 'INTERNO', 1, 'paciente')
    SQL);
    $exam->execute([$atendimentoId, $valor, $valor]);

    return $atendimentoId;
}

it('abre e consulta a sessão da unidade com valores definidos pelo servidor', function () {
    $response = $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-001',
        'valor_abertura' => '150.00',
        'observacoes' => 'Troco inicial',
    ])->assertCreated()
        ->assertJsonPath('data.unidade_id', 'und-001')
        ->assertJsonPath('data.valor_abertura', '150.00')
        ->assertJsonPath('data.status', 'aberta')
        ->assertJsonPath('data.responsavel_id', (string) $this->caixaUser->getKey());

    $id = (int) $response->json('data.id');

    $this->getJson('/api/financeiro/caixa/aberto?unidade_id=und-001')
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'aberta');
});

it('mantém uma única sessão aberta por unidade mas permite unidades diferentes', function () {
    $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-001',
        'valor_abertura' => '0.00',
    ])->assertCreated();

    $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-001',
        'valor_abertura' => '50.00',
    ])->assertConflict();

    $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-002',
        'valor_abertura' => '50.00',
    ])->assertCreated();

    $pdo = caixaOpControlConnection($this->caixaDatabase);
    expect((int) $pdo->query("SELECT count(*) FROM caixa_sessoes WHERE status = 'aberta'")?->fetchColumn())
        ->toBe(2);
});

it('recusa saldo inicial negativo', function () {
    $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-001',
        'valor_abertura' => '-0.01',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('valor_abertura');
});

it('exige gestão financeira para abrir e fechar caixa', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->caixaUser->getKey())
        ->where('tenant_id', $this->caixaTenant->getKey())
        ->update(['role' => 'recepcionista']);

    $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-001',
        'valor_abertura' => '0.00',
    ])->assertForbidden();
});

it('vincula automaticamente somente dinheiro e pix ao caixa aberto da unidade', function () {
    $session = $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-001',
        'valor_abertura' => '0.00',
    ])->assertCreated();
    $sessionId = (int) $session->json('data.id');

    $pdo = caixaOpControlConnection($this->caixaDatabase);
    $atendimentoId = caixaOpCreateAtendimento($pdo, 'und-001');

    foreach ([['Dinheiro', '50.00'], ['PIX', '60.00'], ['Crédito', '70.00']] as [$tipo, $valor]) {
        $this->postJson('/api/financeiro/atendimentos/'.$atendimentoId.'/pagamentos', [
            'tipo' => $tipo,
            'valor' => $valor,
        ])->assertCreated();
    }

    $rows = $pdo->query(sprintf(
        'SELECT tipo, caixa_sessao_id FROM atendimento_pagamentos WHERE atendimento_id = %d ORDER BY id',
        $atendimentoId,
    ))?->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toBe([
        ['tipo' => 'Dinheiro', 'caixa_sessao_id' => $sessionId],
        ['tipo' => 'PIX', 'caixa_sessao_id' => $sessionId],
        ['tipo' => 'Crédito', 'caixa_sessao_id' => null],
    ]);
});

it('fecha caixa com saldo calculado no servidor e ignora pagamento estornado', function () {
    $session = $this->postJson('/api/financeiro/caixa/abrir', [
        'unidade_id' => 'und-001',
        'valor_abertura' => '100.00',
    ])->assertCreated();
    $sessionId = (int) $session->json('data.id');

    $pdo = caixaOpControlConnection($this->caixaDatabase);
    $atendimentoId = caixaOpCreateAtendimento($pdo, 'und-001');

    $money = $this->postJson('/api/financeiro/atendimentos/'.$atendimentoId.'/pagamentos', [
        'tipo' => 'Dinheiro',
        'valor' => '100.00',
    ])->assertCreated();

    $pix = $this->postJson('/api/financeiro/atendimentos/'.$atendimentoId.'/pagamentos', [
        'tipo' => 'PIX',
        'valor' => '50.00',
    ])->assertCreated();

    $this->postJson('/api/financeiro/pagamentos/'.((int) $pix->json('data.id')).'/estorno', [
        'motivo' => 'Pagamento lançado em duplicidade',
    ])->assertOk();

    $saida = $pdo->prepare(<<<'SQL'
        INSERT INTO financeiro_saidas
            (protocolo, descricao, valor, tipo_despesa, destino_pagamento, foi_pago, data_pagamento, forma_pagamento, status)
        VALUES ('SAI-TESTE-001', 'Despesa do caixa', 20.00, 'Outros', 'Fornecedor', true, current_date, 'Dinheiro', 'paga')
        RETURNING caixa_sessao_id
    SQL);
    $saida->execute();
    expect((int) $saida->fetchColumn())->toBe($sessionId);

    $response = $this->postJson('/api/financeiro/caixa/'.$sessionId.'/fechar', [
        'observacoes' => 'Fechamento conferido',
    ])->assertOk()
        ->assertJsonPath('data.sessao_id', $sessionId)
        ->assertJsonPath('data.valor_abertura', '100.00')
        ->assertJsonPath('data.entradas_dinheiro', '100.00')
        ->assertJsonPath('data.entradas_pix', '0.00')
        ->assertJsonPath('data.saidas', '20.00')
        ->assertJsonPath('data.saldo_final', '180.00');

    expect((int) $money->json('data.id'))->toBeGreaterThan(0);

    $persisted = $pdo->query("SELECT status, valor_fechamento::text FROM caixa_sessoes WHERE id = {$sessionId}")?->fetch(PDO::FETCH_ASSOC);
    expect($persisted)->toMatchArray([
        'status' => 'fechada',
        'valor_fechamento' => '180.00',
    ]);

    $this->postJson('/api/financeiro/caixa/'.$sessionId.'/fechar')
        ->assertConflict();
});

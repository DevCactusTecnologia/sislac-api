<?php

use App\Domain\Financeiro\Actions\CloseCaixa;
use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->caixaEstornoDatabase = 'sislac_t_caixa_est_'.Str::lower(Str::random(7));
    caixaEstornoControlConnection()->exec('CREATE DATABASE "'.$this->caixaEstornoDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Caixa Estorno',
        'code' => 'caixa-est-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'database_name' => $this->caixaEstornoDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->caixaEstornoTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->caixaEstornoTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    caixaEstornoControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->caixaEstornoDatabase.'" WITH (FORCE)');
});

function caixaEstornoControlConnection(?string $database = null): PDO
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

it('exclui do fechamento pagamento com estorno canônico mesmo se a flag legada ainda estiver efetuada', function () {
    $pdo = caixaEstornoControlConnection($this->caixaEstornoDatabase);

    $sessionId = (int) $pdo->query(<<<'SQL'
        INSERT INTO caixa_sessoes (unidade_id, valor_abertura)
        VALUES ('und-001', 100.00)
        RETURNING id
    SQL)?->fetchColumn();

    $atendimentoId = (int) $pdo->query(<<<'SQL'
        INSERT INTO atendimentos (paciente_nome, paciente_cpf, unidade_id)
        VALUES ('Paciente Estorno Canônico', '', 'und-001')
        RETURNING id
    SQL)?->fetchColumn();

    $exam = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_exames
            (atendimento_id, nome_exame, valor, valor_original, status, tipo_processo, amostra_seq, cobranca_destino)
        VALUES (?, 'Exame Caixa', 50.00, 50.00, 'pendente', 'INTERNO', 1, 'paciente')
    SQL);
    $exam->execute([$atendimentoId]);

    $payment = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor, caixa_sessao_id)
        VALUES (?, 'Dinheiro', 50.00, ?)
        RETURNING id
    SQL);
    $payment->execute([$atendimentoId, $sessionId]);
    $paymentId = (int) $payment->fetchColumn();

    $estorno = $pdo->prepare(<<<'SQL'
        INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor, criado_por)
        VALUES ('pagamento', ?, 'Estorno canônico importado', 50.00, ?)
    SQL);
    $estorno->execute([$paymentId, (string) Str::uuid()]);

    expect((string) $pdo->query("SELECT status_pagamento FROM atendimento_pagamentos WHERE id = {$paymentId}")?->fetchColumn())
        ->toBe('efetuado');

    tenancy()->initialize($this->caixaEstornoTenant);
    $result = app(CloseCaixa::class)->handle($sessionId, [], (string) Str::uuid());
    tenancy()->end();

    expect($result['entradas_dinheiro'])->toBe('0.00')
        ->and($result['saldo_final'])->toBe('100.00');
});

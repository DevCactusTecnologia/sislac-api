<?php

use App\Domain\Financeiro\Actions\CloseCaixa;
use Illuminate\Support\Str;

beforeEach(function () {
    resetSupabaseFixture();
});

it('exclui do fechamento pagamento com estorno canônico mesmo se a flag legada ainda estiver efetuada', function () {
    $pdo = supabaseTestPdo();

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

    $result = app(CloseCaixa::class)->handle($sessionId, [], (string) Str::uuid());

    expect($result['entradas_dinheiro'])->toBe('0.00')
        ->and($result['saldo_final'])->toBe('100.00');
});

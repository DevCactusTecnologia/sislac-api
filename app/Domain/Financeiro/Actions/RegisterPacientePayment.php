<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Models\AtendimentoPagamento;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RegisterPacientePayment
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(int $atendimentoId, array $payload): AtendimentoPagamento
    {
        return DB::transaction(function () use ($atendimentoId, $payload): AtendimentoPagamento {
            $atendimento = Atendimento::query()
                ->lockForUpdate()
                ->findOrFail($atendimentoId);

            if ($atendimento->getAttribute('status_atendimento') === 'Cancelado') {
                throw new DomainException('Atendimento cancelado não pode receber pagamento.');
            }

            $valor = (string) $payload['valor'];
            $position = $this->paymentPosition($atendimentoId, $valor);

            if ($position['valor_centavos'] <= 0) {
                throw new DomainException('Valor do pagamento deve ser maior que zero.');
            }

            if ($position['saldo_centavos'] <= 0) {
                throw new DomainException('Atendimento não possui saldo a receber do paciente.');
            }

            if ($position['valor_centavos'] > $position['saldo_centavos']) {
                throw new DomainException('Valor do pagamento excede o saldo atual do atendimento.');
            }

            $pagamento = new AtendimentoPagamento;
            $pagamento->fill([
                'tipo' => Str::squish((string) $payload['tipo']),
                'valor' => $valor,
                'data' => $payload['data'] ?? now(),
                'observacao' => isset($payload['observacao'])
                    ? Str::squish((string) $payload['observacao'])
                    : '',
                'status_pagamento' => 'efetuado',
            ]);
            $atendimento->pagamentos()->save($pagamento);

            return $pagamento->refresh();
        });
    }

    /** @return array{valor_centavos:int,saldo_centavos:int} */
    private function paymentPosition(int $atendimentoId, string $valor): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
                ROUND(CAST(? AS numeric) * 100)::bigint AS valor_centavos,
                GREATEST(
                    ROUND((
                        COALESCE((
                            SELECT SUM(e.valor)
                            FROM public.atendimento_exames AS e
                            WHERE e.atendimento_id = ?
                              AND e.status <> 'cancelado'
                              AND COALESCE(e.cobranca_destino, 'paciente') <> 'convenio'
                        ), 0)
                        - COALESCE((
                            SELECT SUM(p.valor)
                            FROM public.atendimento_pagamentos AS p
                            WHERE p.atendimento_id = ?
                              AND COALESCE(p.status_pagamento, 'efetuado') <> 'estornado'
                        ), 0)
                    ) * 100),
                    0
                )::bigint AS saldo_centavos
        SQL, [$valor, $atendimentoId, $atendimentoId]);

        if ($row === null) {
            throw new RuntimeException('Não foi possível calcular a posição financeira do atendimento.');
        }

        return [
            'valor_centavos' => (int) $row->valor_centavos,
            'saldo_centavos' => (int) $row->saldo_centavos,
        ];
    }
}

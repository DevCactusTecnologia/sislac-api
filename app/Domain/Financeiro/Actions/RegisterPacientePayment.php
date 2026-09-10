<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Models\AtendimentoPagamento;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

            $valor = bcadd((string) $payload['valor'], '0', 2);
            $saldo = $this->currentBalance($atendimentoId);

            if (bccomp($valor, '0.00', 2) <= 0) {
                throw new DomainException('Valor do pagamento deve ser maior que zero.');
            }

            if (bccomp($saldo, '0.00', 2) <= 0) {
                throw new DomainException('Atendimento não possui saldo a receber do paciente.');
            }

            if (bccomp($valor, $saldo, 2) > 0) {
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

    private function currentBalance(int $atendimentoId): string
    {
        $devido = DB::table('atendimento_exames')
            ->where('atendimento_id', $atendimentoId)
            ->where('status', '<>', 'cancelado')
            ->whereRaw("COALESCE(cobranca_destino, 'paciente') <> 'convenio'")
            ->sum('valor');

        $pago = DB::table('atendimento_pagamentos')
            ->where('atendimento_id', $atendimentoId)
            ->whereRaw("COALESCE(status_pagamento, 'efetuado') <> 'estornado'")
            ->sum('valor');

        $saldo = bcsub((string) $devido, (string) $pago, 2);

        return bccomp($saldo, '0.00', 2) > 0 ? $saldo : '0.00';
    }
}

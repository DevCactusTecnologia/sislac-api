<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Atendimentos\Models\AtendimentoPagamento;
use App\Domain\Financeiro\Models\FinanceiroEstorno;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReversePacientePayment
{
    /** @return array{pagamento:AtendimentoPagamento,estorno:FinanceiroEstorno} */
    public function handle(int $pagamentoId, string $motivo, string $userId): array
    {
        return DB::transaction(function () use ($pagamentoId, $motivo, $userId): array {
            $pagamento = AtendimentoPagamento::query()
                ->lockForUpdate()
                ->findOrFail($pagamentoId);

            $jaEstornado = $pagamento->getAttribute('status_pagamento') === 'estornado'
                || FinanceiroEstorno::query()
                    ->where('origem_tipo', 'pagamento')
                    ->where('origem_id', $pagamentoId)
                    ->exists();

            if ($jaEstornado) {
                throw new DomainException('Pagamento já foi estornado.');
            }

            $motivo = Str::squish($motivo);
            if ($motivo === '') {
                throw new DomainException('Motivo do estorno é obrigatório.');
            }

            $pagamento->setAttribute('status_pagamento', 'estornado');
            $pagamento->save();

            $estorno = new FinanceiroEstorno;
            $estorno->fill([
                'origem_tipo' => 'pagamento',
                'origem_id' => (int) $pagamento->getKey(),
                'motivo' => $motivo,
                'valor' => (string) $pagamento->getAttribute('valor'),
                'criado_por' => $userId,
            ]);
            $estorno->save();

            return [
                'pagamento' => $pagamento->refresh(),
                'estorno' => $estorno->refresh(),
            ];
        });
    }
}

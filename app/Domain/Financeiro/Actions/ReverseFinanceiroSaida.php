<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Financeiro\Models\FinanceiroEstorno;
use App\Domain\Financeiro\Models\FinanceiroSaida;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReverseFinanceiroSaida
{
    /** @return array{saida: FinanceiroSaida, estorno: FinanceiroEstorno} */
    public function handle(int $id, string $motivo, string $userId): array
    {
        return DB::transaction(function () use ($id, $motivo, $userId): array {
            $saida = FinanceiroSaida::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $existingReversal = FinanceiroEstorno::query()
                ->where('origem_tipo', 'saida')
                ->where('origem_id', $saida->getKey())
                ->exists();

            if ($saida->getAttribute('status') === 'cancelada' || $existingReversal) {
                throw new DomainException('Saída já foi estornada.');
            }

            $motivo = Str::squish($motivo);

            if ($motivo === '') {
                throw new DomainException('O motivo do estorno é obrigatório.');
            }

            $estorno = new FinanceiroEstorno;
            $estorno->fill([
                'origem_tipo' => 'saida',
                'origem_id' => $saida->getKey(),
                'motivo' => $motivo,
                'valor' => (string) $saida->getAttribute('valor'),
                'criado_por' => $userId,
            ]);
            $estorno->save();

            $saida->setAttribute('status', 'cancelada');
            $saida->save();

            return [
                'saida' => $saida->refresh(),
                'estorno' => $estorno->refresh(),
            ];
        });
    }
}

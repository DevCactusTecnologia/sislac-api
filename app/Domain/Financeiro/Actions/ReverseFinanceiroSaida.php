<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Financeiro\Models\FinanceiroEstorno;
use App\Domain\Financeiro\Models\FinanceiroSaida;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReverseFinanceiroSaida
{
    /** @return array{saida:FinanceiroSaida,estorno:FinanceiroEstorno} */
    public function handle(int $id, string $motivo, string $userId): array
    {
        return DB::transaction(function () use ($id, $motivo, $userId): array {
            $saida = FinanceiroSaida::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $jaEstornada = $saida->getAttribute('status') === 'cancelada'
                || FinanceiroEstorno::query()
                    ->where('origem_tipo', 'saida')
                    ->where('origem_id', $saida->getKey())
                    ->exists();

            if ($jaEstornada) {
                throw new DomainException('Saída já foi estornada.');
            }

            $motivo = Str::squish($motivo);
            if ($motivo === '') {
                throw new DomainException('Motivo do estorno é obrigatório.');
            }

            // O estorno canônico precisa existir antes da transição para cancelada;
            // o trigger do banco valida exatamente essa ordem.
            $estorno = FinanceiroEstorno::query()->create([
                'origem_tipo' => 'saida',
                'origem_id' => $saida->getKey(),
                'motivo' => $motivo,
                'valor' => $saida->getAttribute('valor'),
                'criado_por' => $userId,
            ]);

            $saida->setAttribute('status', 'cancelada');
            $saida->save();

            return [
                'saida' => $saida->refresh(),
                'estorno' => $estorno->refresh(),
            ];
        });
    }
}

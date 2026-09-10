<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Financeiro\Models\FinanceiroSaida;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateFinanceiroSaida
{
    /** @param array<string, mixed> $payload */
    public function handle(int $id, array $payload): FinanceiroSaida
    {
        return DB::transaction(function () use ($id, $payload): FinanceiroSaida {
            $saida = FinanceiroSaida::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($saida->getAttribute('status') !== 'aberta') {
                throw new DomainException('Saída paga ou cancelada não pode ser editada; use estorno.');
            }

            foreach (['data', 'valor', 'data_vencimento', 'status', 'data_pagamento'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $saida->setAttribute($field, $payload[$field]);
                }
            }

            foreach (['descricao', 'tipo_despesa', 'destino_pagamento', 'forma_pagamento'] as $field) {
                if (! array_key_exists($field, $payload)) {
                    continue;
                }

                $value = $payload[$field];
                $saida->setAttribute($field, is_string($value) ? Str::squish($value) : $value);
            }

            $saida->save();

            return $saida->refresh();
        });
    }
}

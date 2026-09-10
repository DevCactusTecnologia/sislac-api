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
                throw new DomainException('Saída financeira não está aberta para edição.');
            }

            $saida->fill($this->attributes($payload));
            $saida->save();

            return $saida->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attributes(array $payload): array
    {
        $attributes = [];

        foreach (['descricao', 'tipo_despesa', 'destino_pagamento'] as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = Str::squish((string) $payload[$field]);
            }
        }

        foreach (['valor', 'data', 'data_vencimento', 'data_pagamento', 'status'] as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = $payload[$field];
            }
        }

        if (array_key_exists('forma_pagamento', $payload)) {
            $forma = $payload['forma_pagamento'];
            $attributes['forma_pagamento'] = is_string($forma) && Str::squish($forma) !== ''
                ? Str::squish($forma)
                : null;
        }

        return $attributes;
    }
}

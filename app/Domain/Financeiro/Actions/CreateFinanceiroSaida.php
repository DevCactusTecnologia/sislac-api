<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Financeiro\Models\FinanceiroSaida;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateFinanceiroSaida
{
    /** @param array<string, mixed> $payload */
    public function handle(array $payload): FinanceiroSaida
    {
        return DB::transaction(function () use ($payload): FinanceiroSaida {
            $saida = new FinanceiroSaida;
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
        $attributes = [
            'descricao' => Str::squish((string) $payload['descricao']),
            'valor' => $payload['valor'],
            'tipo_despesa' => Str::squish((string) $payload['tipo_despesa']),
            'destino_pagamento' => Str::squish((string) $payload['destino_pagamento']),
            'status' => isset($payload['status']) ? (string) $payload['status'] : 'aberta',
        ];

        foreach (['data', 'data_vencimento', 'data_pagamento'] as $field) {
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

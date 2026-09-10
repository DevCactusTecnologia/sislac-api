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
            $saida->fill([
                'data' => $payload['data'] ?? now(),
                'descricao' => Str::squish((string) $payload['descricao']),
                'valor' => (string) $payload['valor'],
                'tipo_despesa' => Str::squish((string) $payload['tipo_despesa']),
                'destino_pagamento' => Str::squish((string) $payload['destino_pagamento']),
                'data_vencimento' => $payload['data_vencimento'] ?? null,
                'forma_pagamento' => isset($payload['forma_pagamento'])
                    ? Str::squish((string) $payload['forma_pagamento'])
                    : null,
                'status' => (string) ($payload['status'] ?? 'aberta'),
                'data_pagamento' => $payload['data_pagamento'] ?? null,
            ]);
            $saida->save();

            return $saida->refresh();
        });
    }
}

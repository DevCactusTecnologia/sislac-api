<?php

namespace App\Http\Resources\Financeiro;

use App\Domain\Financeiro\Models\FinanceiroSaida;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FinanceiroSaida */
final class FinanceiroSaidaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'protocolo' => (string) $this->getAttribute('protocolo'),
            'data' => $this->getAttribute('data')?->toISOString(),
            'descricao' => (string) $this->getAttribute('descricao'),
            'valor' => (string) $this->getAttribute('valor'),
            'tipo_despesa' => (string) $this->getAttribute('tipo_despesa'),
            'destino_pagamento' => (string) $this->getAttribute('destino_pagamento'),
            'data_vencimento' => $this->getAttribute('data_vencimento')?->toDateString(),
            'status' => (string) $this->getAttribute('status'),
            'foi_pago' => (bool) $this->getAttribute('foi_pago'),
            'data_pagamento' => $this->getAttribute('data_pagamento')?->toDateString(),
            'forma_pagamento' => $this->getAttribute('forma_pagamento'),
            'caixa_sessao_id' => $this->getAttribute('caixa_sessao_id'),
            'created_at' => $this->getAttribute('created_at')?->toISOString(),
            'updated_at' => $this->getAttribute('updated_at')?->toISOString(),
        ];
    }
}

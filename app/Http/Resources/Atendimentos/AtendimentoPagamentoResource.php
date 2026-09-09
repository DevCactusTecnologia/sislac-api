<?php

namespace App\Http\Resources\Atendimentos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AtendimentoPagamentoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'tipo' => $this->resource->tipo,
            'valor' => $this->resource->valor,
            'data' => $this->resource->data?->toISOString(),
            'observacao' => $this->resource->observacao,
            'status_pagamento' => $this->resource->status_pagamento,
            'caixa_sessao_id' => $this->resource->caixa_sessao_id,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}

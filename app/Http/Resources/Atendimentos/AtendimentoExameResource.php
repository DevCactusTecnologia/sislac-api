<?php

namespace App\Http\Resources\Atendimentos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AtendimentoExameResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'exame_id' => $this->resource->exame_id,
            'nome_exame' => $this->resource->nome_exame,
            'status' => $this->resource->status,
            'valor' => $this->resource->valor,
            'valor_original' => $this->resource->valor_original,
            'ordem' => $this->resource->ordem,
            'tipo_processo' => $this->resource->tipo_processo,
            'amostra_seq' => $this->resource->amostra_seq,
            'grupo_exame_id' => $this->resource->grupo_exame_id,
            'amostra_id' => $this->resource->amostra_id,
            'data_coleta' => $this->resource->data_coleta?->toISOString(),
            'data_analise' => $this->resource->data_analise?->toISOString(),
            'data_liberacao' => $this->resource->data_liberacao?->toISOString(),
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}

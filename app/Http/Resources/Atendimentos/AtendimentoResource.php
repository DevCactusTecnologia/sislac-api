<?php

namespace App\Http\Resources\Atendimentos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AtendimentoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'protocolo' => $this->resource->protocolo,
            'data' => $this->resource->data?->toISOString(),
            'paciente_id' => $this->resource->paciente_id,
            'paciente_nome' => $this->resource->paciente_nome,
            'paciente_cpf' => $this->resource->paciente_cpf,
            'paciente_nascimento' => $this->resource->paciente_nascimento?->format('Y-m-d'),
            'solicitante' => $this->resource->solicitante,
            'convenio_id' => $this->resource->convenio_id,
            'convenio_nome' => $this->resource->convenio_nome,
            'unidade_id' => $this->resource->unidade_id,
            'status_atendimento' => $this->resource->status_atendimento,
            'status_pagamento' => $this->resource->status_pagamento,
            'motivo_cancelamento' => $this->resource->motivo_cancelamento,
            'origem_atendimento' => $this->resource->origem_atendimento,
            'tem_retificacao' => $this->resource->tem_retificacao,
            'guia_numero' => $this->resource->guia_numero,
            'guia_data' => $this->resource->guia_data?->format('Y-m-d'),
            'subtotal' => $this->resource->subtotal,
            'desconto_total' => $this->resource->desconto_total,
            'acrescimo_total' => $this->resource->acrescimo_total,
            'total' => $this->resource->total,
            'jejum' => $this->resource->jejum,
            'observacoes_assistente' => $this->resource->observacoes_assistente,
            'risco_cardiovascular' => $this->resource->risco_cardiovascular,
            'prioridade_clinica' => $this->resource->prioridade_clinica,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
            'exames' => AtendimentoExameResource::collection($this->whenLoaded('exames')),
            'pagamentos' => AtendimentoPagamentoResource::collection($this->whenLoaded('pagamentos')),
        ];
    }
}

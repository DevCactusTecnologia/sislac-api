<?php

namespace App\Http\Resources\Financeiro;

use App\Domain\Financeiro\Models\CaixaSessao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CaixaSessao */
final class CaixaSessaoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'unidade_id' => (string) $this->getAttribute('unidade_id'),
            'aberta_em' => $this->getAttribute('aberta_em')?->toISOString(),
            'fechada_em' => $this->getAttribute('fechada_em')?->toISOString(),
            'responsavel_id' => $this->getAttribute('responsavel_id'),
            'valor_abertura' => (string) $this->getAttribute('valor_abertura'),
            'valor_fechamento' => $this->getAttribute('valor_fechamento'),
            'observacoes' => $this->getAttribute('observacoes'),
            'status' => (string) $this->getAttribute('status'),
            'fechado_por' => $this->getAttribute('fechado_por'),
        ];
    }
}

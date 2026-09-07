<?php

namespace App\Http\Resources\Pacientes;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PacienteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'nome' => $this->resource->nome,
            'nome_social' => $this->resource->nome_social,
            'cpf' => $this->resource->cpf,
            'data_nascimento' => $this->resource->data_nascimento?->format('Y-m-d'),
            'sexo' => $this->resource->sexo,
            'telefone' => $this->resource->telefone,
            'celular' => $this->resource->celular,
            'email' => $this->resource->email,
            'cep' => $this->resource->cep,
            'estado' => $this->resource->estado,
            'cidade' => $this->resource->cidade,
            'bairro' => $this->resource->bairro,
            'endereco' => $this->resource->endereco,
            'numero' => $this->resource->numero,
            'complemento' => $this->resource->complemento,
            'status' => $this->resource->status,
            'guardian_name' => $this->resource->guardian_name,
            'guardian_cpf' => $this->resource->guardian_cpf,
            'consentimento_lgpd' => $this->resource->consentimento_lgpd,
            'consentimento_em' => $this->resource->consentimento_em?->toISOString(),
            'friendly_id' => $this->resource->friendly_id,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class OpenCaixaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        $unidadeId = $this->input('unidade_id');
        if (is_string($unidadeId)) {
            $normalized['unidade_id'] = Str::squish($unidadeId);
        }

        $observacoes = $this->input('observacoes');
        if (is_string($observacoes)) {
            $normalized['observacoes'] = Str::squish($observacoes);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unidade_id' => ['required', 'string', 'min:1', 'max:255'],
            'valor_abertura' => ['sometimes', 'numeric', 'gte:0', 'decimal:0,2'],
            'responsavel_id' => ['missing'],
            'status' => ['missing'],
            'aberta_em' => ['missing'],
            'fechada_em' => ['missing'],
            'valor_fechamento' => ['missing'],
            'fechado_por' => ['missing'],
            'observacoes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

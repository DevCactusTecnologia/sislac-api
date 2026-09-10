<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class CloseCaixaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $observacoes = $this->input('observacoes');

        if (is_string($observacoes)) {
            $this->merge(['observacoes' => Str::squish($observacoes)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sessao_id' => ['missing'],
            'unidade_id' => ['missing'],
            'valor_abertura' => ['missing'],
            'valor_fechamento' => ['missing'],
            'entradas_dinheiro' => ['missing'],
            'entradas_pix' => ['missing'],
            'saidas' => ['missing'],
            'saldo_final' => ['missing'],
            'status' => ['missing'],
            'fechada_em' => ['missing'],
            'fechado_por' => ['missing'],
            'observacoes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

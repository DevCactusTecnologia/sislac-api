<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateFinanceiroSaidaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['descricao', 'tipo_despesa', 'destino_pagamento', 'forma_pagamento'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = Str::squish($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'data' => ['sometimes', 'date'],
            'descricao' => ['sometimes', 'string', 'max:2000'],
            'valor' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2'],
            'tipo_despesa' => ['sometimes', 'string', 'max:255'],
            'destino_pagamento' => ['sometimes', 'string', 'max:255'],
            'data_vencimento' => ['sometimes', 'nullable', 'date'],
            'forma_pagamento' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['aberta', 'paga'])],
            'data_pagamento' => ['sometimes', 'nullable', 'date', 'prohibited_unless:status,paga'],
            'id' => ['missing'],
            'protocolo' => ['missing'],
            'assinatura_protocolo' => ['missing'],
            'foi_pago' => ['missing'],
            'caixa_sessao_id' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}

<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreFinanceiroSaidaRequest extends FormRequest
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
            'descricao' => ['required', 'string', 'max:2000'],
            'valor' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tipo_despesa' => ['required', 'string', 'max:255'],
            'destino_pagamento' => ['required', 'string', 'max:255'],
            'data' => ['sometimes', 'date'],
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

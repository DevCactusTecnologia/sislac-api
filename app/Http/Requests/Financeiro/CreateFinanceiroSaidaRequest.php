<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateFinanceiroSaidaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'regex:/\S/u'],
            'valor' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tipo_despesa' => ['required', 'string', 'regex:/\S/u'],
            'destino_pagamento' => ['required', 'string', 'regex:/\S/u'],
            'data' => ['sometimes', 'date'],
            'data_vencimento' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'forma_pagamento' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['aberta', 'paga'])],
            'data_pagamento' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                'prohibited_unless:status,paga',
            ],
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

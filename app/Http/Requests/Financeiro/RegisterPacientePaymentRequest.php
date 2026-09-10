<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterPacientePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', 'min:1', 'max:100'],
            'valor' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'data' => ['sometimes', 'nullable', 'date'],
            'observacao' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

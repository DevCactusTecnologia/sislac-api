<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class RegisterPacientePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        $tipo = $this->input('tipo');
        if (is_string($tipo)) {
            $normalized['tipo'] = Str::squish($tipo);
        }

        $observacao = $this->input('observacao');
        if (is_string($observacao)) {
            $normalized['observacao'] = Str::squish($observacao);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
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

<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ReverseFinanceiroSaidaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $motivo = $this->input('motivo');

        if (is_string($motivo)) {
            $this->merge(['motivo' => Str::squish($motivo)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}

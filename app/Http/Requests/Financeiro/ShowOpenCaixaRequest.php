<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ShowOpenCaixaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $unidadeId = $this->input('unidade_id');

        if (is_string($unidadeId)) {
            $this->merge(['unidade_id' => Str::squish($unidadeId)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unidade_id' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}

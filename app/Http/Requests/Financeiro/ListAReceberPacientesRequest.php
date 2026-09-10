<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAReceberPacientesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['pendente', 'parcial'])],
            'cursor_data' => ['sometimes', 'nullable', 'date'],
            'cursor_id' => ['required_with:cursor_data', 'nullable', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}

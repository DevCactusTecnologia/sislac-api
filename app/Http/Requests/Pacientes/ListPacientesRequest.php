<?php

namespace App\Http\Requests\Pacientes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListPacientesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['Todos', 'Ativo', 'Inativo'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:160'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }
}

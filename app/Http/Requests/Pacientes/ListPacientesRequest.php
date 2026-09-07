<?php

namespace App\Http\Requests\Pacientes;

use App\Domain\Pacientes\Support\PacienteCursor;
use Closure;
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
            'cursor' => [
                'sometimes',
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! PacienteCursor::isValid($value)) {
                        $fail('O cursor de pacientes é inválido.');
                    }
                },
            ],
        ];
    }
}

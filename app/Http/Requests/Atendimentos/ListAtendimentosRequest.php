<?php

namespace App\Http\Requests\Atendimentos;

use App\Domain\Atendimentos\Support\AtendimentoCursor;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class ListAtendimentosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', 'max:80'],
            'pagamento' => ['sometimes', 'nullable', 'string', 'max:80'],
            'unidade_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'data_inicio' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'data_fim' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'q' => ['sometimes', 'nullable', 'string', 'max:160'],
            'page_size' => ['sometimes', 'nullable', 'integer'],
            'cursor' => [
                'sometimes',
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! AtendimentoCursor::isValid($value)) {
                        $fail('O cursor de atendimentos é inválido.');
                    }
                },
            ],
        ];
    }
}

<?php

namespace App\Http\Requests\Financeiro;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use JsonException;

final class ListFinanceiroSaidasRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['aberta', 'paga', 'cancelada'])],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512', $this->validCursor(...)],
        ];
    }

    private function validCursor(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value)) {
                $fail('O cursor é inválido.');

                return;
            }

            $normalized = strtr($value, '-_', '+/');
            $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
            $decoded = base64_decode($normalized, true);

            if ($decoded === false) {
                $fail('O cursor é inválido.');

                return;
            }

            try {
                $payload = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $fail('O cursor é inválido.');

                return;
            }

            if (! is_array($payload)
                || ! isset($payload['data'], $payload['id'])
                || ! is_string($payload['data'])
                || ! is_int($payload['id'])
                || $payload['id'] < 1) {
                $fail('O cursor é inválido.');
            }
        };
    }
}

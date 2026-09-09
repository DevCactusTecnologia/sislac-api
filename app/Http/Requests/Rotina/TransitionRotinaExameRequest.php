<?php

namespace App\Http\Requests\Rotina;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionRotinaExameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'acao' => [
                'required',
                'string',
                Rule::in(['coletar', 'recoletar', 'iniciar_analise', 'finalizar_analise', 'cancelar']),
            ],
            'motivo' => ['nullable', 'string', 'required_if:acao,cancelar'],
            'status' => ['prohibited'],
            'data_coleta' => ['prohibited'],
            'data_analise' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        foreach (['acao', 'motivo'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $payload[$key] = trim($payload[$key]);
            }
        }

        $this->replace($payload);
    }
}

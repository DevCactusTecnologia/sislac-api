<?php

namespace App\Http\Requests\Rotina;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRotinaConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rotina_fluxo_modo' => [
                'required',
                'string',
                Rule::in(['completo', 'coleta_resultado', 'apenas_resultado']),
            ],
        ];
    }
}

<?php

namespace App\Http\Requests\Pacientes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nome' => ['sometimes', 'required', 'string'],
            'nome_social' => ['sometimes', 'nullable', 'string'],
            'cpf' => ['sometimes', 'nullable', 'regex:/^\d{11}$/'],
            'data_nascimento' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sexo' => ['sometimes', 'string', Rule::in(['M', 'F'])],
            'telefone' => ['sometimes', 'string'],
            'celular' => ['sometimes', 'string'],
            'email' => ['sometimes', 'string'],
            'cep' => ['sometimes', 'string'],
            'estado' => ['sometimes', 'string'],
            'cidade' => ['sometimes', 'string'],
            'bairro' => ['sometimes', 'string'],
            'endereco' => ['sometimes', 'string'],
            'numero' => ['sometimes', 'string'],
            'complemento' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['Ativo', 'Inativo'])],
            'guardian_name' => ['sometimes', 'nullable', 'string'],
            'guardian_cpf' => ['sometimes', 'nullable', 'regex:/^\d{11}$/'],
            'consentimento_lgpd' => ['sometimes', 'boolean'],
            'consentimento_em' => ['sometimes', 'nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->replace(app(PacienteInputNormalizer::class)->normalize($this->all()));
    }
}

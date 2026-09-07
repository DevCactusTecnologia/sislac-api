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
        $input = $this->all();
        $normalized = [];

        foreach (['telefone', 'celular', 'email', 'cep', 'estado', 'cidade', 'bairro', 'endereco', 'numero', 'complemento'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === null) {
                $normalized[$field] = '';
            }
        }

        foreach (['nome_social', 'guardian_name', 'consentimento_em'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $normalized[$field] = null;
            }
        }

        if (array_key_exists('cpf', $input)) {
            $normalized['cpf'] = $this->digitsOrNull($input['cpf']);
        }

        if (array_key_exists('guardian_cpf', $input)) {
            $normalized['guardian_cpf'] = $this->digitsOrNull($input['guardian_cpf']);
        }

        if (array_key_exists('data_nascimento', $input)) {
            $normalized['data_nascimento'] = $this->normalizeDate($input['data_nascimento']);
        }

        if (array_key_exists('sexo', $input) && is_string($input['sexo'])) {
            $normalized['sexo'] = match ($input['sexo']) {
                'Masculino' => 'M',
                'Feminino' => 'F',
                default => $input['sexo'],
            };
        }

        $this->merge($normalized);
    }

    private function digitsOrNull(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function normalizeDate(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $parts)) {
            return $value;
        }

        return $parts[3].'-'.$parts[2].'-'.$parts[1];
    }
}

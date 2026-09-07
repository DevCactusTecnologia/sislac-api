<?php

namespace App\Http\Requests\Pacientes;

final class PacienteInputNormalizer
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $normalized = $input;

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

        return $normalized;
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

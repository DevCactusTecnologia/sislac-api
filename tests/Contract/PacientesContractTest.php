<?php

use App\Http\Requests\Pacientes\PacienteInputNormalizer;

it('fixa o contrato do módulo de pacientes no baseline aprovado', function () {
    $contract = json_decode(
        (string) file_get_contents(base_path('docs/contracts/pacientes.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($contract['baseline']['frontend_sha'])->toBe('0760c6123f3062842eaff5f7304b6460c00c058d')
        ->and($contract['baseline']['supabase_project_ref'])->toBe('eramenhnqcbyctyiqwlm')
        ->and($contract['supabase']['table'])->toBe('public.pacientes')
        ->and($contract['supabase']['rls_enabled'])->toBeTrue()
        ->and($contract['supabase']['columns'])->toHaveCount(24)
        ->and($contract['laravel']['pagination']['page_size'])->toBe(50)
        ->and($contract['laravel']['pagination']['offset'])->toBeFalse()
        ->and($contract['laravel']['permissions'])->toBe([
            'index' => 'visualizar_pacientes',
            'show' => 'visualizar_pacientes',
            'store' => 'cadastrar_paciente',
            'update' => 'editar_paciente',
        ])
        ->and($contract['laravel']['conflicts']['duplicate_cpf_status'])->toBe(422)
        ->and($contract['laravel']['friendly_id']['immutable'])->toBeTrue();
});

it('mantém equivalentes as transformações sintéticas observadas no frontend', function (array $input, array $expected) {
    $normalized = app(PacienteInputNormalizer::class)->normalize($input);

    expect($normalized)->toMatchArray($expected);
})->with([
    'cadastro completo' => [
        [
            'nome' => 'Maria Sintética',
            'nome_social' => '',
            'cpf' => '123.456.789-01',
            'data_nascimento' => '07/09/1990',
            'sexo' => 'Feminino',
            'guardian_name' => '',
            'guardian_cpf' => '987.654.321-00',
            'consentimento_em' => '',
        ],
        [
            'nome' => 'Maria Sintética',
            'nome_social' => null,
            'cpf' => '12345678901',
            'data_nascimento' => '1990-09-07',
            'sexo' => 'F',
            'guardian_name' => null,
            'guardian_cpf' => '98765432100',
            'consentimento_em' => null,
        ],
    ],
    'campos vazios e sexo masculino' => [
        [
            'cpf' => '',
            'guardian_cpf' => '',
            'data_nascimento' => '',
            'sexo' => 'Masculino',
            'telefone' => null,
            'email' => null,
        ],
        [
            'cpf' => null,
            'guardian_cpf' => null,
            'data_nascimento' => null,
            'sexo' => 'M',
            'telefone' => '',
            'email' => '',
        ],
    ],
    'patch preserva chaves ausentes' => [
        ['status' => 'Inativo'],
        ['status' => 'Inativo'],
    ],
]);

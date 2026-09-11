<?php

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    resetSupabaseFixture();
});

it('mantém o contrato físico necessário ao backend de pacientes', function () {
    expect(Schema::hasColumns('pacientes', [
        'id', 'nome', 'nome_social', 'cpf', 'data_nascimento', 'sexo', 'telefone', 'celular',
        'email', 'cep', 'estado', 'cidade', 'bairro', 'endereco', 'numero', 'complemento',
        'status', 'guardian_name', 'guardian_cpf', 'consentimento_lgpd', 'consentimento_em',
        'friendly_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('permite cpf vazio mas impede cpf não vazio duplicado', function () {
    $pdo = supabaseTestPdo();

    $pdo->exec("INSERT INTO pacientes (nome, cpf) VALUES ('Paciente Sem CPF 1', NULL)");
    $pdo->exec("INSERT INTO pacientes (nome, cpf) VALUES ('Paciente Sem CPF 2', NULL)");
    $pdo->exec("INSERT INTO pacientes (nome, cpf) VALUES ('Paciente CPF', '12345678901')");

    expect(fn () => $pdo->exec(
        "INSERT INTO pacientes (nome, cpf) VALUES ('Paciente CPF Duplicado', '12345678901')",
    ))->toThrow(PDOException::class);
});

it('rejeita sexo fora do contrato', function () {
    expect(fn () => supabaseTestPdo()->exec(
        "INSERT INTO pacientes (nome, sexo) VALUES ('Paciente Sexo Inválido', 'X')",
    ))->toThrow(PDOException::class);
});

it('rejeita status fora do contrato', function () {
    expect(fn () => supabaseTestPdo()->exec(
        "INSERT INTO pacientes (nome, status) VALUES ('Paciente Status Inválido', 'Excluído')",
    ))->toThrow(PDOException::class);
});

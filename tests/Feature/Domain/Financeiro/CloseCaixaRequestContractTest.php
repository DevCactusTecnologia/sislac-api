<?php

use App\Http\Requests\Financeiro\CloseCaixaRequest;
use Illuminate\Support\Facades\Validator;

it('recusa campos derivados de fechamento enviados pelo cliente', function () {
    $request = new CloseCaixaRequest;
    $payload = [
        'sessao_id' => 999,
        'unidade_id' => 'outra-unidade',
        'valor_abertura' => '999.00',
        'valor_fechamento' => '999.00',
        'entradas_dinheiro' => '999.00',
        'entradas_pix' => '999.00',
        'saidas' => '0.00',
        'saldo_final' => '999.00',
        'status' => 'fechada',
        'fechada_em' => now()->toISOString(),
        'fechado_por' => '00000000-0000-0000-0000-000000000000',
        'observacoes' => 'Conferido',
    ];

    $validator = Validator::make($payload, $request->rules());

    expect($validator->fails())->toBeTrue();

    foreach (array_keys($payload) as $field) {
        if ($field === 'observacoes') {
            continue;
        }

        expect($validator->errors()->has($field))->toBeTrue();
    }
});

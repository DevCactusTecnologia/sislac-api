<?php

use App\Http\Requests\Financeiro\OpenCaixaRequest;
use Illuminate\Support\Facades\Validator;

it('recusa campos de estado da sessão enviados pelo cliente na abertura', function () {
    $request = new OpenCaixaRequest;
    $payload = [
        'unidade_id' => 'und-001',
        'valor_abertura' => '100.00',
        'responsavel_id' => '00000000-0000-0000-0000-000000000000',
        'status' => 'fechada',
        'aberta_em' => now()->toISOString(),
        'fechada_em' => now()->toISOString(),
        'valor_fechamento' => '999.00',
        'fechado_por' => '00000000-0000-0000-0000-000000000000',
        'observacoes' => 'Troco inicial',
    ];

    $validator = Validator::make($payload, $request->rules());

    expect($validator->fails())->toBeTrue();

    foreach ([
        'responsavel_id',
        'status',
        'aberta_em',
        'fechada_em',
        'valor_fechamento',
        'fechado_por',
    ] as $field) {
        expect($validator->errors()->has($field))->toBeTrue();
    }
});

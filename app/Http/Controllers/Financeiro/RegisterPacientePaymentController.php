<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\RegisterPacientePayment;
use App\Http\Requests\Financeiro\RegisterPacientePaymentRequest;
use DomainException;
use Illuminate\Http\JsonResponse;

final class RegisterPacientePaymentController
{
    public function __invoke(
        RegisterPacientePaymentRequest $request,
        RegisterPacientePayment $action,
        int $id,
    ): JsonResponse {
        try {
            $pagamento = $action->handle($id, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'data' => [
                'id' => (int) $pagamento->getKey(),
                'atendimento_id' => (int) $pagamento->getAttribute('atendimento_id'),
                'tipo' => (string) $pagamento->getAttribute('tipo'),
                'valor' => (string) $pagamento->getAttribute('valor'),
                'data' => $pagamento->getAttribute('data')?->toISOString(),
                'observacao' => (string) $pagamento->getAttribute('observacao'),
                'status_pagamento' => (string) $pagamento->getAttribute('status_pagamento'),
            ],
        ], 201);
    }
}

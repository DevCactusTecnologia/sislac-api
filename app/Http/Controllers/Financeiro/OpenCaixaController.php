<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\OpenCaixa;
use App\Http\Requests\Financeiro\OpenCaixaRequest;
use App\Http\Resources\Financeiro\CaixaSessaoResource;
use DomainException;
use Illuminate\Http\JsonResponse;

final class OpenCaixaController
{
    public function __invoke(
        OpenCaixaRequest $request,
        OpenCaixa $action,
    ): JsonResponse {
        $userId = $request->user()?->getAuthIdentifier();
        if (! is_string($userId) || $userId === '') {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        try {
            $session = $action->handle($request->validated(), $userId);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'data' => (new CaixaSessaoResource($session))->resolve($request),
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Atendimentos;

use App\Domain\Atendimentos\Actions\CreateAtendimento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Atendimentos\StoreAtendimentoRequest;
use Illuminate\Http\JsonResponse;

final class StoreAtendimentoController extends Controller
{
    public function __invoke(StoreAtendimentoRequest $request, CreateAtendimento $createAtendimento): JsonResponse
    {
        $result = $createAtendimento->handle($request->validated());

        return response()->json(
            $result,
            $result['duplicate'] ? 200 : 201,
        );
    }
}

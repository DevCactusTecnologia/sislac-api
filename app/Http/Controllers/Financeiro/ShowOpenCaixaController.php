<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Queries\FindOpenCaixaByUnit;
use App\Http\Requests\Financeiro\ShowOpenCaixaRequest;
use App\Http\Resources\Financeiro\CaixaSessaoResource;
use Illuminate\Http\JsonResponse;

final class ShowOpenCaixaController
{
    public function __invoke(
        ShowOpenCaixaRequest $request,
        FindOpenCaixaByUnit $query,
    ): JsonResponse {
        $session = $query->handle((string) $request->validated('unidade_id'));

        return response()->json([
            'data' => $session === null
                ? null
                : (new CaixaSessaoResource($session))->resolve($request),
        ]);
    }
}

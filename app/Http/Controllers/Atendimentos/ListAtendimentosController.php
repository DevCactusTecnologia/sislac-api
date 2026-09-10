<?php

namespace App\Http\Controllers\Atendimentos;

use App\Domain\Atendimentos\Queries\ListAtendimentos;
use App\Http\Requests\Atendimentos\ListAtendimentosRequest;
use App\Http\Resources\Atendimentos\AtendimentoResource;
use Illuminate\Http\JsonResponse;

final class ListAtendimentosController
{
    public function __invoke(ListAtendimentosRequest $request, ListAtendimentos $query): JsonResponse
    {
        $result = $query->handle($request->validated());

        return response()->json([
            'data' => AtendimentoResource::collection($result['data'])->resolve($request),
            'meta' => [
                'nextCursor' => $result['next_cursor'],
            ],
        ]);
    }
}

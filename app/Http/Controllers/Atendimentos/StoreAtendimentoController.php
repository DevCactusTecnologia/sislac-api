<?php

namespace App\Http\Controllers\Atendimentos;

use App\Domain\Atendimentos\Actions\CreateAtendimento;
use App\Http\Requests\Atendimentos\StoreAtendimentoRequest;
use Illuminate\Http\JsonResponse;

final readonly class StoreAtendimentoController
{
    public function __construct(private CreateAtendimento $createAtendimento) {}

    public function __invoke(StoreAtendimentoRequest $request): JsonResponse
    {
        return response()->json(
            $this->createAtendimento->handle($request->validated()),
        );
    }
}

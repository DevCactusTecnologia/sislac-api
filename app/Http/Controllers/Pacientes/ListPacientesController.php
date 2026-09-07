<?php

namespace App\Http\Controllers\Pacientes;

use App\Domain\Pacientes\Queries\ListPacientes;
use App\Http\Requests\Pacientes\ListPacientesRequest;
use App\Http\Resources\Pacientes\PacienteResource;
use Illuminate\Http\JsonResponse;

final class ListPacientesController
{
    public function __invoke(ListPacientesRequest $request, ListPacientes $query): JsonResponse
    {
        $result = $query->execute(
            $request->string('status', 'Todos')->toString(),
            $request->string('q')->toString(),
            $request->string('cursor')->toString(),
        );

        return response()->json([
            'data' => PacienteResource::collection($result['data'])->resolve($request),
            'meta' => [
                'counts' => $result['counts'],
                'nextCursor' => $result['nextCursor'],
            ],
        ]);
    }
}

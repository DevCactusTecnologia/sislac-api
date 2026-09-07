<?php

namespace App\Http\Controllers\Pacientes;

use App\Domain\Pacientes\Actions\CreatePaciente;
use App\Domain\Pacientes\Services\PacienteFriendlyId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pacientes\StorePacienteRequest;
use App\Http\Resources\Pacientes\PacienteResource;
use Illuminate\Http\JsonResponse;

final class CreatePacienteController extends Controller
{
    public function __invoke(
        StorePacienteRequest $request,
        CreatePaciente $action,
        PacienteFriendlyId $friendlyId,
    ): JsonResponse {
        $paciente = $action->execute($request->validated(), $friendlyId);

        return (new PacienteResource($paciente))
            ->response()
            ->setStatusCode(201);
    }
}

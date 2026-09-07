<?php

namespace App\Http\Controllers\Pacientes;

use App\Domain\Pacientes\Actions\UpdatePaciente;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pacientes\UpdatePacienteRequest;
use App\Http\Resources\Pacientes\PacienteResource;
use Illuminate\Http\JsonResponse;

final class UpdatePacienteController extends Controller
{
    public function __invoke(
        UpdatePacienteRequest $request,
        UpdatePaciente $action,
        int $id,
    ): JsonResponse {
        $paciente = $action->execute($id, $request->validated());

        return (new PacienteResource($paciente))->response();
    }
}

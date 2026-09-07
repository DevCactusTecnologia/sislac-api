<?php

namespace App\Http\Controllers\Pacientes;

use App\Domain\Pacientes\Models\Paciente;
use App\Http\Resources\Pacientes\PacienteResource;
use Illuminate\Http\Resources\Json\JsonResource;

final class ShowPacienteController
{
    public function __invoke(int $id): JsonResource
    {
        return new PacienteResource(Paciente::query()->findOrFail($id));
    }
}

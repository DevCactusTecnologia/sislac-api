<?php

namespace App\Domain\Pacientes\Actions;

use App\Domain\Pacientes\Models\Paciente;
use App\Domain\Pacientes\Services\PacienteFriendlyId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreatePaciente
{
    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, PacienteFriendlyId $friendlyId): Paciente
    {
        try {
            return DB::transaction(function () use ($attributes, $friendlyId): Paciente {
                $paciente = new Paciente;
                $paciente->fill($attributes);
                $paciente->friendly_id = $friendlyId->next();
                $paciente->save();

                return $paciente->refresh();
            });
        } catch (QueryException $exception) {
            $this->throwIfCpfConflict($exception);
            throw $exception;
        }
    }

    private function throwIfCpfConflict(QueryException $exception): void
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        if ($sqlState === '23505' && str_contains($exception->getMessage(), 'pacientes_cpf_unique_nonempty')) {
            throw ValidationException::withMessages([
                'cpf' => ['CPF já cadastrado para este laboratório.'],
            ]);
        }
    }
}

<?php

namespace App\Domain\Pacientes\Actions;

use App\Domain\Pacientes\Models\Paciente;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdatePaciente
{
    public function execute(int $id, array $attributes): Paciente
    {
        try {
            return DB::transaction(function () use ($id, $attributes): Paciente {
                $paciente = Paciente::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                $paciente->fill($attributes);
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

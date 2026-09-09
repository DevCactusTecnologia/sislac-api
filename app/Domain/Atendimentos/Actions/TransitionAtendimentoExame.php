<?php

namespace App\Domain\Atendimentos\Actions;

use App\Domain\Atendimentos\Models\AtendimentoExame;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class TransitionAtendimentoExame
{
    public function handle(
        int $id,
        string $action,
        string $actorId,
        string $actorName,
        string $actorEmail,
        ?string $reason = null,
    ): AtendimentoExame {
        try {
            return DB::transaction(function () use ($id, $action, $actorId, $actorName, $actorEmail, $reason): AtendimentoExame {
                $this->setAuditContext($actorId, $actorEmail, $action === 'cancelar' ? $reason : null);

                $exam = AtendimentoExame::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                $mode = $this->mode();
                $status = (string) $exam->getAttribute('status');

                if ($action === 'recoletar' && $status === 'finalizado') {
                    throw new DomainException('Exame finalizado não pode ser reaberto nesta etapa.');
                }

                $attributes = $this->attributes($action, $mode, $actorName, $exam);
                $exam->fill($attributes);
                $exam->save();

                return $exam->refresh();
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23514') {
                throw new DomainException('Transição incompatível com o estado atual ou o fluxo configurado.', 0, $exception);
            }

            throw $exception;
        }
    }

    private function mode(): string
    {
        $mode = DB::table('lab_config')
            ->where('singleton_key', 1)
            ->value('rotina_fluxo_modo');

        return is_string($mode) && $mode !== '' ? $mode : 'completo';
    }

    /** @return array<string, mixed> */
    private function attributes(
        string $action,
        string $mode,
        string $actorName,
        AtendimentoExame $exam,
    ): array {
        return match ($action) {
            'coletar' => [
                'status' => 'coletado',
                'data_coleta' => now(),
                'coletor' => $actorName,
            ],
            'recoletar' => $mode === 'apenas_resultado'
                ? [
                    'status' => 'analisado',
                    'data_coleta' => now(),
                    'data_analise' => now(),
                    'coletor' => '__SEM_REGISTRO__',
                    'analista' => '__SEM_REGISTRO__',
                ]
                : [
                    'status' => 'pendente',
                    'data_coleta' => null,
                    'data_analise' => null,
                    'coletor' => '',
                    'analista' => '',
                ],
            'iniciar_analise' => [
                'status' => 'em_bancada',
                'data_analise' => now(),
                'analista' => $actorName,
            ],
            'finalizar_analise' => [
                'status' => 'analisado',
                'data_analise' => $exam->getAttribute('data_analise') ?? now(),
                'analista' => $actorName,
            ],
            'cancelar' => ['status' => 'cancelado'],
            default => throw new DomainException('Ação de rotina inválida.'),
        };
    }

    private function setAuditContext(string $actorId, string $actorEmail, ?string $reason): void
    {
        DB::selectOne("SELECT set_config('app.audit_user_id', ?, true)", [$actorId]);
        DB::selectOne("SELECT set_config('app.audit_user_email', ?, true)", [$actorEmail]);
        DB::selectOne("SELECT set_config('app.audit_justificativa', ?, true)", [$reason ?? '']);
    }
}

<?php

namespace App\Domain\Atendimentos\Actions;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Models\AtendimentoExame;
use App\Domain\Atendimentos\Models\AtendimentoPagamento;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateAtendimento
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(int $id, array $payload, ?string $justificativa): Atendimento
    {
        return DB::transaction(function () use ($id, $payload, $justificativa): Atendimento {
            $this->setAuditContext($payload, $justificativa);

            $atendimento = Atendimento::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $parentAttributes = $this->parentAttributes($payload);

            if ($parentAttributes !== []) {
                $atendimento->fill($parentAttributes);
                $atendimento->save();
            }

            if (array_key_exists('exames', $payload)) {
                $this->replaceExames($atendimento, $payload['exames']);
            }

            if (array_key_exists('pagamentos', $payload)) {
                $this->replacePagamentos($atendimento, $payload['pagamentos']);
            }

            if (($payload['cancelar'] ?? false) === true) {
                $this->cancel($atendimento, $payload);
            }

            return $atendimento->refresh()->load(['exames', 'pagamentos']);
        });
    }

    /** @param array<string, mixed> $payload */
    private function setAuditContext(array $payload, ?string $justificativa): void
    {
        $userId = $payload['_audit_user_id'] ?? '';
        $userEmail = $payload['_audit_user_email'] ?? '';

        DB::selectOne(
            "SELECT set_config('app.audit_user_id', ?, true)",
            [is_string($userId) ? $userId : ''],
        );
        DB::selectOne(
            "SELECT set_config('app.audit_user_email', ?, true)",
            [is_string($userEmail) ? $userEmail : ''],
        );
        DB::selectOne(
            "SELECT set_config('app.audit_justificativa', ?, true)",
            [$justificativa ?? ''],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function parentAttributes(array $payload): array
    {
        return $this->only($payload, [
            'data',
            'paciente_id',
            'paciente_nome',
            'paciente_cpf',
            'paciente_nascimento',
            'solicitante',
            'convenio_id',
            'convenio_nome',
            'unidade_id',
            'origem_atendimento',
            'guia_numero',
            'guia_data',
            'jejum',
            'observacoes_assistente',
            'risco_cardiovascular',
            'prioridade_clinica',
        ]);
    }

    private function replaceExames(Atendimento $atendimento, mixed $rows): void
    {
        if (! is_array($rows)) {
            return;
        }

        $existing = $atendimento->exames()->get();
        $byOccurrence = [];

        foreach ($existing as $exame) {
            $byOccurrence[$this->occurrenceKeyFromModel($exame)] = $exame;
        }

        $keptIds = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $this->occurrenceKeyFromArray($row);
            $matched = $byOccurrence[$key] ?? null;

            if ($matched instanceof AtendimentoExame) {
                $matched->fill($this->existingExamAttributes($row));
                $matched->save();
                $keptIds[] = (int) $matched->getKey();

                continue;
            }

            $exame = new AtendimentoExame;
            $exame->fill($this->newExamAttributes($row));
            $atendimento->exames()->save($exame);
            $keptIds[] = (int) $exame->getKey();
        }

        $stale = $atendimento->exames();

        if ($keptIds !== []) {
            $stale->whereNotIn('id', $keptIds);
        }

        $stale->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function existingExamAttributes(array $row): array
    {
        $attributes = $this->only($row, [
            'exame_id',
            'nome_exame',
            'valor',
            'tipo_processo',
            'amostra_seq',
            'grupo_exame_id',
            'amostra_id',
            'material_id',
            'mnemonico_exame',
            'lab_apoio_id',
            'cobranca_destino',
            'convenio_cobranca_id',
        ]);

        if (isset($attributes['nome_exame']) && is_string($attributes['nome_exame'])) {
            $attributes['nome_exame'] = Str::squish($attributes['nome_exame']);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function newExamAttributes(array $row): array
    {
        $attributes = $this->only($row, [
            'exame_id',
            'nome_exame',
            'valor',
            'valor_original',
            'ordem',
            'tipo_processo',
            'amostra_seq',
            'grupo_exame_id',
            'amostra_id',
            'material_id',
            'mnemonico_exame',
            'lab_apoio_id',
            'cobranca_destino',
            'convenio_cobranca_id',
        ]);

        if (isset($attributes['nome_exame']) && is_string($attributes['nome_exame'])) {
            $attributes['nome_exame'] = Str::squish($attributes['nome_exame']);
        }

        $tipoProcesso = $attributes['tipo_processo'] ?? 'INTERNO';
        $attributes['tipo_processo'] = $tipoProcesso;
        $attributes['status'] = $tipoProcesso === 'TERCEIRIZADO' ? 'digitado' : 'pendente';

        if (! array_key_exists('valor_original', $attributes)) {
            $attributes['valor_original'] = $attributes['valor'] ?? 0;
        }

        return $attributes;
    }

    private function occurrenceKeyFromModel(AtendimentoExame $exame): string
    {
        $exameId = $exame->getAttribute('exame_id');
        $name = $exame->getAttribute('nome_exame');
        $sample = (int) ($exame->getAttribute('amostra_seq') ?? 1);

        if (is_string($exameId) && $exameId !== '') {
            return 'id:'.Str::lower($exameId).'#'.$sample;
        }

        return 'name:'.$this->normalizeExamName(is_string($name) ? $name : '').'#'.$sample;
    }

    /** @param array<string, mixed> $row */
    private function occurrenceKeyFromArray(array $row): string
    {
        $exameId = $row['exame_id'] ?? null;
        $name = $row['nome_exame'] ?? '';
        $sample = isset($row['amostra_seq']) && is_numeric($row['amostra_seq'])
            ? (int) $row['amostra_seq']
            : 1;

        if (is_string($exameId) && $exameId !== '') {
            return 'id:'.Str::lower($exameId).'#'.$sample;
        }

        return 'name:'.$this->normalizeExamName(is_string($name) ? $name : '').'#'.$sample;
    }

    private function normalizeExamName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    private function replacePagamentos(Atendimento $atendimento, mixed $rows): void
    {
        if (! is_array($rows)) {
            return;
        }

        $hasUnmigratedDependency = $atendimento->pagamentos()
            ->where(function ($query): void {
                $query->whereNotNull('caixa_sessao_id')
                    ->orWhere('status_pagamento', '<>', 'efetuado');
            })
            ->exists();

        if ($hasUnmigratedDependency) {
            throw new DomainException('Pagamento vinculado a Caixa/estorno ainda não pode ser alterado nesta onda.');
        }

        $atendimento->pagamentos()->delete();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $attributes = $this->only($row, [
                'tipo',
                'valor',
                'data',
                'observacao',
            ]);
            $attributes['status_pagamento'] = 'efetuado';

            $pagamento = new AtendimentoPagamento;
            $pagamento->fill($attributes);
            $atendimento->pagamentos()->save($pagamento);
        }
    }

    /** @param array<string, mixed> $payload */
    private function cancel(Atendimento $atendimento, array $payload): void
    {
        $reason = $payload['motivo_cancelamento'] ?? null;
        $reason = is_string($reason) ? $reason : '';

        $atendimento->motivo_cancelamento = $reason;
        $atendimento->save();

        $atendimento->exames()
            ->where('status', '<>', 'cancelado')
            ->update([
                'status' => 'cancelado',
                'motivo_cancelamento' => $reason,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $source, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $result[$key] = $source[$key];
            }
        }

        return $result;
    }
}

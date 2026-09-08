<?php

namespace App\Domain\Atendimentos\Actions;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Models\AtendimentoExame;
use App\Domain\Atendimentos\Models\AtendimentoPagamento;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CreateAtendimento
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,duplicate:bool,protocolo:string,atendimento_id:int,guia_numero:?string}
     */
    public function handle(array $payload): array
    {
        $idempotencyKey = $this->idempotencyKey($payload);

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                return $this->result($existing, true);
            }
        }

        try {
            return DB::transaction(function () use ($payload, $idempotencyKey): array {
                if ($idempotencyKey !== null) {
                    $existing = $this->findByIdempotencyKey($idempotencyKey);

                    if ($existing !== null) {
                        return $this->result($existing, true);
                    }
                }

                $atendimento = new Atendimento;
                $atendimento->fill($this->parentAttributes($payload));
                $atendimento->save();

                foreach ($this->exames($payload) as $attributes) {
                    $exame = new AtendimentoExame;
                    $exame->fill($attributes);
                    $atendimento->exames()->save($exame);
                }

                foreach ($this->pagamentos($payload) as $attributes) {
                    $pagamento = new AtendimentoPagamento;
                    $pagamento->fill($attributes);
                    $atendimento->pagamentos()->save($pagamento);
                }

                return $this->result($atendimento->refresh(), false);
            });
        } catch (QueryException $exception) {
            if (! $this->isIdempotencyConflict($exception, $idempotencyKey)) {
                throw $exception;
            }

            $existing = $idempotencyKey === null
                ? null
                : $this->findByIdempotencyKey($idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $this->result($existing, true);
        }
    }

    /** @param array<string, mixed> $payload */
    private function idempotencyKey(array $payload): ?string
    {
        $value = $payload['idempotency_key'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function findByIdempotencyKey(string $key): ?Atendimento
    {
        return Atendimento::query()
            ->where('idempotency_key', $key)
            ->first();
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
            'idempotency_key',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function exames(array $payload): array
    {
        $rows = $payload['exames'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

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
                'mnemonico',
                'lab_apoio_id',
                'cobranca_destino',
                'convenio_cobranca_id',
            ]);

            $tipoProcesso = $attributes['tipo_processo'] ?? 'INTERNO';
            $attributes['tipo_processo'] = $tipoProcesso;
            $attributes['status'] = $tipoProcesso === 'TERCEIRIZADO' ? 'digitado' : 'pendente';

            if (! array_key_exists('valor_original', $attributes)) {
                $attributes['valor_original'] = $attributes['valor'] ?? 0;
            }

            $result[] = $attributes;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function pagamentos(array $payload): array
    {
        $rows = $payload['pagamentos'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $result = [];

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
            $result[] = $attributes;
        }

        return $result;
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

    private function isIdempotencyConflict(QueryException $exception, ?string $idempotencyKey): bool
    {
        if ($idempotencyKey === null) {
            return false;
        }

        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === '23505'
            && str_contains($exception->getMessage(), 'atendimentos_idempotency_key_unique');
    }

    /** @return array{ok:bool,duplicate:bool,protocolo:string,atendimento_id:int,guia_numero:?string} */
    private function result(Atendimento $atendimento, bool $duplicate): array
    {
        $protocolo = $atendimento->getAttribute('protocolo');

        if (! is_string($protocolo) || $protocolo === '') {
            throw new LogicException('Atendimento persistido sem protocolo válido.');
        }

        $guiaNumero = $atendimento->getAttribute('guia_numero');

        return [
            'ok' => true,
            'duplicate' => $duplicate,
            'protocolo' => $protocolo,
            'atendimento_id' => (int) $atendimento->getKey(),
            'guia_numero' => is_string($guiaNumero) && $guiaNumero !== '' ? $guiaNumero : null,
        ];
    }
}

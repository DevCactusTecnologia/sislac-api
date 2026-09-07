<?php

namespace App\Domain\Atendimentos\Actions;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Support\AtendimentoProtocolo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CreateAtendimento
{
    public function __construct(private AtendimentoProtocolo $protocolos) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $data = $payload['atendimento'] ?? [];

            if (! is_array($data)) {
                $data = [];
            }

            $atendimento = new Atendimento;
            $atendimento->fill(Arr::only($data, [
                'data',
                'paciente_id',
                'paciente_nome',
                'paciente_cpf',
                'paciente_nascimento',
                'solicitante',
                'convenio_id',
                'convenio_nome',
                'unidade_id',
                'motivo_cancelamento',
                'idempotency_key',
                'origem_atendimento',
                'jejum',
                'prioridade_clinica',
                'guia_numero',
                'observacoes_assistente',
            ]));
            $atendimento->forceFill(['protocolo' => $this->protocolos->next()]);
            $atendimento->save();

            return [
                'ok' => true,
                'protocolo' => $atendimento->protocolo,
                'atendimento_id' => $atendimento->getKey(),
                'guia_numero' => $atendimento->guia_numero,
            ];
        });
    }
}

<?php

namespace App\Domain\Atendimentos\Actions;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Models\AtendimentoExame;
use App\Domain\Atendimentos\Models\AtendimentoPagamento;
use App\Domain\Atendimentos\Support\AtendimentoProtocolo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $data = $this->objectValue($payload, 'atendimento');
            $idempotencyKey = $data['idempotency_key'] ?? null;

            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $existing = Atendimento::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $this->response($existing, duplicate: true);
                }
            }

            if (! array_key_exists('observacoes_assistente', $data)
                && array_key_exists('observacoes', $data)) {
                $data['observacoes_assistente'] = $data['observacoes'];
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

            $this->persistExames($atendimento, $this->listValue($payload, 'exames'));
            $this->persistPagamentos($atendimento, $this->listValue($payload, 'pagamentos'));

            return $this->response($atendimento, duplicate: false);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function objectValue(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, mixed>
     */
    private function listValue(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    /**
     * @param  array<int, mixed>  $exames
     */
    private function persistExames(Atendimento $atendimento, array $exames): void
    {
        $sequenceByIdentity = [];
        $groupByIdentity = [];

        foreach ($exames as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = (string) ($item['nome_exame'] ?? '');
            $examId = $item['exame_id'] ?? null;
            $identity = is_string($examId) && $examId !== ''
                ? $examId
                : mb_strtolower($name);

            $sequenceByIdentity[$identity] = ($sequenceByIdentity[$identity] ?? 0) + 1;
            $sequence = isset($item['amostra_seq'])
                ? (int) $item['amostra_seq']
                : $sequenceByIdentity[$identity];

            $groupId = $item['grupo_exame_id'] ?? null;
            if (! is_string($groupId) || $groupId === '') {
                $groupByIdentity[$identity] ??= (string) Str::uuid();
                $groupId = $groupByIdentity[$identity];
            } else {
                $groupByIdentity[$identity] ??= $groupId;
            }

            $type = (string) ($item['tipo_processo'] ?? 'INTERNO');
            $value = $item['valor'] ?? 0;
            $originalValue = array_key_exists('valor_original', $item) && $item['valor_original'] !== null
                ? $item['valor_original']
                : $value;

            $exam = new AtendimentoExame;
            $exam->fill([
                'atendimento_id' => $atendimento->getKey(),
                'nome_exame' => $name,
                'exame_id' => $examId,
                'material_id' => $item['material_id'] ?? null,
                'status' => $item['status'] ?? ($type === 'TERCEIRIZADO' ? 'digitado' : 'pendente'),
                'valor' => $value,
                'valor_original' => $originalValue,
                'ordem' => $item['ordem'] ?? ($index + 1),
                'cobranca_destino' => $item['cobranca_destino'] ?? 'paciente',
                'convenio_cobranca_id' => $item['convenio_cobranca_id'] ?? null,
                'amostra_seq' => $sequence,
                'grupo_exame_id' => $groupId,
                'tipo_processo' => $type,
                'lab_apoio_id' => $type === 'TERCEIRIZADO' ? ($item['lab_apoio_id'] ?? null) : null,
                'solicitante' => $item['solicitante'] ?? '',
            ]);
            $exam->save();
        }
    }

    /**
     * @param  array<int, mixed>  $pagamentos
     */
    private function persistPagamentos(Atendimento $atendimento, array $pagamentos): void
    {
        foreach ($pagamentos as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['tipo'] ?? null;
            if (! is_string($type) || trim($type) === '') {
                continue;
            }

            $payment = new AtendimentoPagamento;
            $payment->fill([
                'atendimento_id' => $atendimento->getKey(),
                'tipo' => $type,
                'valor' => $item['valor'] ?? 0,
            ]);

            if (isset($item['data']) && $item['data'] !== '') {
                $payment->setAttribute('data', $item['data']);
            }

            $payment->save();
        }
    }

    /** @return array<string, mixed> */
    private function response(Atendimento $atendimento, bool $duplicate): array
    {
        return [
            'ok' => true,
            'duplicate' => $duplicate,
            'protocolo' => $atendimento->protocolo,
            'atendimento_id' => $atendimento->getKey(),
            'guia_numero' => $atendimento->guia_numero,
        ];
    }
}

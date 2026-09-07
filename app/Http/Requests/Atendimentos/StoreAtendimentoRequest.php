<?php

namespace App\Http\Requests\Atendimentos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAtendimentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'atendimento' => ['required', 'array'],
            'atendimento.data' => ['nullable', 'date'],
            'atendimento.paciente_id' => ['nullable', 'integer'],
            'atendimento.paciente_nome' => ['required', 'string'],
            'atendimento.paciente_cpf' => ['present', 'string'],
            'atendimento.paciente_nascimento' => ['nullable', 'date'],
            'atendimento.solicitante' => ['nullable', 'string'],
            'atendimento.convenio_id' => ['nullable', 'integer'],
            'atendimento.convenio_nome' => ['nullable', 'string'],
            'atendimento.unidade_id' => ['nullable', 'string'],
            'atendimento.motivo_cancelamento' => ['nullable', 'string'],
            'atendimento.idempotency_key' => ['nullable', 'uuid'],
            'atendimento.origem_atendimento' => ['nullable', Rule::in(['INTERNO', 'WEB_AUTO', 'WEB_APROVADO', 'AGENDAMENTO'])],
            'atendimento.jejum' => ['nullable', 'boolean'],
            'atendimento.prioridade_clinica' => ['nullable', Rule::in(['normal', 'urgencia', 'emergencia'])],
            'atendimento.guia_numero' => ['nullable', 'string'],
            'atendimento.observacoes_assistente' => ['nullable', 'string'],
            'atendimento.observacoes' => ['nullable', 'string'],

            'exames' => ['sometimes', 'array'],
            'exames.*' => ['array'],
            'exames.*.nome_exame' => ['required', 'string'],
            'exames.*.exame_id' => ['nullable', 'uuid'],
            'exames.*.material_id' => ['nullable', 'uuid'],
            'exames.*.status' => ['nullable', Rule::in(['pendente', 'coletado', 'em_bancada', 'analisado', 'em_analise', 'digitado', 'finalizado', 'cancelado'])],
            'exames.*.valor' => ['nullable', 'numeric'],
            'exames.*.valor_original' => ['nullable', 'numeric'],
            'exames.*.ordem' => ['nullable', 'integer'],
            'exames.*.cobranca_destino' => ['nullable', Rule::in(['paciente', 'convenio'])],
            'exames.*.convenio_cobranca_id' => ['nullable', 'integer'],
            'exames.*.amostra_seq' => ['nullable', 'integer'],
            'exames.*.grupo_exame_id' => ['nullable', 'uuid'],
            'exames.*.tipo_processo' => ['nullable', Rule::in(['INTERNO', 'TERCEIRIZADO'])],
            'exames.*.lab_apoio_id' => ['nullable', 'uuid'],
            'exames.*.solicitante' => ['nullable', 'string'],

            'pagamentos' => ['sometimes', 'array'],
            'pagamentos.*' => ['array'],
            'pagamentos.*.tipo' => ['required', 'string'],
            'pagamentos.*.valor' => ['required', 'numeric'],
            'pagamentos.*.data' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $atendimento = $this->input('atendimento');

        if (! is_array($atendimento)) {
            return;
        }

        $cpf = $atendimento['paciente_cpf'] ?? null;

        if (is_string($cpf)) {
            $atendimento['paciente_cpf'] = preg_replace('/\D+/', '', $cpf) ?? '';
            $this->merge(['atendimento' => $atendimento]);
        }
    }
}

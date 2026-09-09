<?php

namespace App\Http\Requests\Atendimentos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAtendimentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'data' => ['sometimes', 'date'],
            'paciente_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'paciente_nome' => ['sometimes', 'string', 'min:1'],
            'paciente_cpf' => ['sometimes', 'nullable', 'string'],
            'paciente_nascimento' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'solicitante' => ['sometimes', 'string'],
            'convenio_id' => ['sometimes', 'integer', 'min:0'],
            'convenio_nome' => ['sometimes', 'string'],
            'unidade_id' => ['sometimes', 'string'],
            'origem_atendimento' => ['sometimes', 'string', Rule::in(['INTERNO', 'WEB_AUTO', 'WEB_APROVADO', 'AGENDAMENTO'])],
            'guia_numero' => ['sometimes', 'nullable', 'string'],
            'guia_data' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'jejum' => ['sometimes', 'boolean'],
            'observacoes_assistente' => ['sometimes', 'nullable', 'string'],
            'risco_cardiovascular' => ['sometimes', 'nullable', 'string', Rule::in(['baixo', 'intermediario', 'alto', 'muito_alto'])],
            'prioridade_clinica' => ['sometimes', 'string', Rule::in(['normal', 'urgencia', 'emergencia'])],
            'cancelar' => ['sometimes', 'boolean'],
            'motivo_cancelamento' => ['required_if:cancelar,true', 'nullable', 'string'],
            'justificativa' => ['sometimes', 'nullable', 'string'],
            'exames' => ['sometimes', 'array'],
            'exames.*.exame_id' => ['sometimes', 'nullable', 'uuid'],
            'exames.*.nome_exame' => ['required', 'string', 'min:1'],
            'exames.*.valor' => ['sometimes', 'numeric', 'min:0'],
            'exames.*.valor_original' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'exames.*.ordem' => ['sometimes', 'integer', 'min:0'],
            'exames.*.tipo_processo' => ['sometimes', 'string', Rule::in(['INTERNO', 'TERCEIRIZADO'])],
            'exames.*.amostra_seq' => ['sometimes', 'integer', 'min:1'],
            'exames.*.grupo_exame_id' => ['sometimes', 'uuid'],
            'exames.*.amostra_id' => ['sometimes', 'nullable', 'uuid'],
            'exames.*.material_id' => ['sometimes', 'nullable', 'uuid'],
            'exames.*.mnemonico_exame' => ['sometimes', 'nullable', 'string'],
            'exames.*.lab_apoio_id' => ['sometimes', 'nullable', 'uuid'],
            'exames.*.cobranca_destino' => ['sometimes', 'string', Rule::in(['paciente', 'convenio'])],
            'exames.*.convenio_cobranca_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'pagamentos' => ['sometimes', 'array'],
            'pagamentos.*.tipo' => ['required', 'string', 'min:1'],
            'pagamentos.*.valor' => ['required', 'numeric', 'min:0'],
            'pagamentos.*.data' => ['sometimes', 'date'],
            'pagamentos.*.observacao' => ['sometimes', 'string'],
        ];
    }
}

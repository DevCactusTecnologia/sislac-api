<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atendimentos', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('protocolo');
            $table->timestampTz('data')->useCurrent();
            $table->unsignedBigInteger('paciente_id')->nullable();
            $table->text('paciente_nome');
            $table->text('paciente_cpf');
            $table->date('paciente_nascimento')->nullable();
            $table->text('solicitante')->default('');
            $table->integer('convenio_id')->default(0);
            $table->text('convenio_nome')->default('Particular');
            $table->text('unidade_id')->default('und-001');
            $table->text('status_atendimento')->default('Pedido Realizado');
            $table->text('status_pagamento')->default('Pagamento pendente');
            $table->text('motivo_cancelamento')->nullable();
            $table->text('assinatura_protocolo')->nullable();
            $table->text('origem_atendimento')->default('INTERNO');
            $table->boolean('tem_retificacao')->default(false);
            $table->text('guia_numero')->nullable();
            $table->date('guia_data')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('desconto_total', 14, 2)->default(0);
            $table->decimal('acrescimo_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->boolean('jejum')->default(false);
            $table->text('observacoes_assistente')->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->text('risco_cardiovascular')->nullable();
            $table->text('prioridade_clinica')->default('normal');
            $table->text('senha_consulta')->nullable();
            $table->timestampTz('senha_consulta_expira_em')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique('protocolo', 'atendimentos_protocolo_unique');
            $table->index('paciente_cpf', 'idx_atendimentos_cpf');
            $table->index('data', 'idx_atendimentos_data');
            $table->index('paciente_id', 'idx_atendimentos_paciente_id');
            $table->index('status_atendimento', 'idx_atendimentos_status_at');
            $table->index('unidade_id', 'idx_atendimentos_unidade');
        });

        Schema::create('atendimento_exames', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('atendimento_id');
            $table->uuid('exame_id')->nullable();
            $table->text('nome_exame');
            $table->text('status')->default('pendente');
            $table->decimal('valor', 14, 2)->default(0);
            $table->text('analista')->default('');
            $table->text('coletor')->default('');
            $table->timestampTz('data_coleta')->nullable();
            $table->timestampTz('data_analise')->nullable();
            $table->timestampTz('data_liberacao')->nullable();
            $table->jsonb('resultados')->default(DB::raw("'{}'::jsonb"));
            $table->text('motivo_cancelamento')->nullable();
            $table->integer('ordem')->default(0);
            $table->text('tipo_processo')->default('INTERNO');
            $table->uuid('lab_apoio_id')->nullable();
            $table->boolean('integracao_ativa')->default(false);
            $table->text('status_externo')->default('NAO_APLICAVEL');
            $table->text('protocolo_externo')->nullable();
            $table->timestampTz('data_envio')->nullable();
            $table->timestampTz('data_retorno')->nullable();
            $table->boolean('resultado_importado')->default(false);
            $table->text('arquivo_resultado_path')->nullable();
            $table->text('cobranca_destino')->default('paciente');
            $table->integer('convenio_cobranca_id')->nullable();
            $table->integer('amostra_seq')->default(1);
            $table->uuid('grupo_exame_id')->default(DB::raw('gen_random_uuid()'));
            $table->uuid('amostra_id')->nullable();
            $table->boolean('is_reutilizacao')->default(false);
            $table->text('pop_versao')->default('');
            $table->unsignedBigInteger('pop_id')->nullable();
            $table->text('solicitante')->default('');
            $table->text('pdf_override_url')->nullable();
            $table->uuid('pdf_override_uploaded_by')->nullable();
            $table->timestampTz('pdf_override_uploaded_at')->nullable();
            $table->text('pdf_override_motivo')->nullable();
            $table->text('pdf_override_replaced_path')->nullable();
            $table->text('metodologia_snapshot')->nullable();
            $table->text('unidade_snapshot')->nullable();
            $table->boolean('retificado')->default(false);
            $table->timestampTz('retificado_at')->nullable();
            $table->decimal('valor_original', 14, 2)->nullable();
            $table->uuid('material_id')->nullable();
            $table->text('mnemonico_exame')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('atendimento_id')->references('id')->on('atendimentos')->cascadeOnDelete();
            $table->index('atendimento_id', 'idx_at_exames_atendimento');
            $table->index(['atendimento_id', 'status'], 'idx_atendimento_exames_atendimento_status');
        });

        Schema::create('atendimento_pagamentos', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('atendimento_id');
            $table->text('tipo');
            $table->decimal('valor', 14, 2)->default(0);
            $table->timestampTz('data')->useCurrent();
            $table->text('observacao')->default('');
            $table->text('status_pagamento')->default('efetuado');
            $table->unsignedBigInteger('caixa_sessao_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('atendimento_id')->references('id')->on('atendimentos')->cascadeOnDelete();
            $table->index('atendimento_id', 'idx_atendimento_pagamentos_atendimento');
            $table->index('data', 'idx_at_pagamentos_data');
        });

        DB::statement("ALTER TABLE atendimentos ADD CONSTRAINT atendimentos_origem_chk CHECK (origem_atendimento IN ('INTERNO', 'WEB_AUTO', 'WEB_APROVADO', 'AGENDAMENTO'))");
        DB::statement("ALTER TABLE atendimentos ADD CONSTRAINT atendimentos_prioridade_clinica_check CHECK (prioridade_clinica IN ('normal', 'urgencia', 'emergencia'))");
        DB::statement("ALTER TABLE atendimentos ADD CONSTRAINT atendimentos_risco_cv_chk CHECK (risco_cardiovascular IS NULL OR risco_cardiovascular IN ('baixo', 'intermediario', 'alto', 'muito_alto'))");
        DB::statement("ALTER TABLE atendimento_exames ADD CONSTRAINT atendimento_exames_status_check CHECK (status IN ('pendente', 'coletado', 'em_bancada', 'analisado', 'em_analise', 'digitado', 'finalizado', 'cancelado'))");
        DB::statement("ALTER TABLE atendimento_exames ADD CONSTRAINT atendimento_exames_status_externo_check CHECK (status_externo IN ('NAO_APLICAVEL', 'AGUARDANDO_ENVIO', 'ENVIADO', 'EM_ANALISE_LAB', 'RESULTADO_RECEBIDO', 'IMPORTADO', 'FINALIZADO', 'ERRO_INTEGRACAO'))");
        DB::statement("ALTER TABLE atendimento_exames ADD CONSTRAINT atendimento_exames_tipo_processo_check CHECK (tipo_processo IN ('INTERNO', 'TERCEIRIZADO'))");
        DB::statement("ALTER TABLE atendimento_exames ADD CONSTRAINT atex_cobranca_destino_chk CHECK (cobranca_destino IN ('paciente', 'convenio'))");
        DB::statement("CREATE UNIQUE INDEX atendimento_exames_unico_amostra ON atendimento_exames (atendimento_id, COALESCE(exame_id::text, lower(nome_exame)), amostra_seq)");
        DB::statement('CREATE INDEX idx_atendimentos_nome_trgm ON atendimentos USING gin (lower(paciente_nome) gin_trgm_ops)');
        DB::statement('CREATE INDEX idx_atendimentos_protocolo_trgm ON atendimentos USING gin (lower(protocolo) gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('atendimento_pagamentos');
        Schema::dropIfExists('atendimento_exames');
        Schema::dropIfExists('atendimentos');
    }
};

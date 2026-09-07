<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocolo_sequence', function (Blueprint $table): void {
            $table->text('prefixo');
            $table->integer('ano');
            $table->integer('ultimo_numero')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['prefixo', 'ano']);
        });

        Schema::create('atendimentos', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('protocolo')->unique();
            $table->timestampTz('data')->useCurrent();
            $table->unsignedBigInteger('paciente_id')->nullable()->index();
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
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->text('assinatura_protocolo')->nullable();
            $table->text('origem_atendimento')->default('INTERNO');
            $table->boolean('tem_retificacao')->default(false);
            $table->text('guia_numero')->nullable();
            $table->date('guia_data')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('desconto_total', 12, 2)->default(0);
            $table->decimal('acrescimo_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('jejum')->default(false);
            $table->text('observacoes_assistente')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->text('risco_cardiovascular')->nullable();
            $table->text('prioridade_clinica')->default('normal');
            $table->text('senha_consulta')->nullable();
            $table->timestampTz('senha_consulta_expira_em')->nullable();

            $table->index('data');
            $table->index('paciente_cpf');
            $table->index('status_atendimento');
            $table->index('unidade_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE atendimentos
            ADD CONSTRAINT atendimentos_origem_chk
            CHECK (origem_atendimento IN ('INTERNO', 'WEB_AUTO', 'WEB_APROVADO', 'AGENDAMENTO'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE atendimentos
            ADD CONSTRAINT atendimentos_prioridade_clinica_check
            CHECK (prioridade_clinica IN ('normal', 'urgencia', 'emergencia'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE atendimentos
            ADD CONSTRAINT atendimentos_risco_cv_chk
            CHECK (risco_cardiovascular IS NULL OR risco_cardiovascular IN ('baixo', 'intermediario', 'alto', 'muito_alto'))
        SQL);

        Schema::create('atendimento_exames', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('atendimento_id')->constrained('atendimentos')->cascadeOnDelete();
            $table->uuid('exame_id')->nullable();
            $table->text('nome_exame');
            $table->text('status')->default('pendente');
            $table->decimal('valor', 10, 2)->default(0);
            $table->text('analista')->default('');
            $table->text('coletor')->default('');
            $table->timestampTz('data_coleta')->nullable();
            $table->timestampTz('data_analise')->nullable();
            $table->timestampTz('data_liberacao')->nullable();
            $table->jsonb('resultados')->default(DB::raw("'{}'::jsonb"));
            $table->text('motivo_cancelamento')->nullable();
            $table->integer('ordem')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
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
            $table->decimal('valor_original', 12, 2)->nullable();
            $table->uuid('material_id')->nullable();
            $table->text('mnemonico_exame')->nullable();

            $table->index(['atendimento_id', 'status']);
            $table->index('exame_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE atendimento_exames
            ADD CONSTRAINT atendimento_exames_status_check
            CHECK (status IN ('pendente', 'coletado', 'em_bancada', 'analisado', 'em_analise', 'digitado', 'finalizado', 'cancelado'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE atendimento_exames
            ADD CONSTRAINT atendimento_exames_status_externo_check
            CHECK (status_externo IN ('NAO_APLICAVEL', 'AGUARDANDO_ENVIO', 'ENVIADO', 'EM_ANALISE_LAB', 'RESULTADO_RECEBIDO', 'IMPORTADO', 'FINALIZADO', 'ERRO_INTEGRACAO'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE atendimento_exames
            ADD CONSTRAINT atendimento_exames_tipo_processo_check
            CHECK (tipo_processo IN ('INTERNO', 'TERCEIRIZADO'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE atendimento_exames
            ADD CONSTRAINT atex_cobranca_destino_chk
            CHECK (cobranca_destino IN ('paciente', 'convenio'))
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX atendimento_exames_unico_amostra
            ON atendimento_exames (atendimento_id, COALESCE(exame_id::text, lower(nome_exame)), amostra_seq)
        SQL);

        Schema::create('atendimento_pagamentos', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('atendimento_id')->constrained('atendimentos')->cascadeOnDelete();
            $table->text('tipo');
            $table->decimal('valor', 10, 2)->default(0);
            $table->timestampTz('data')->useCurrent();
            $table->text('observacao')->default('');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->text('status_pagamento')->default('efetuado');
            $table->unsignedBigInteger('caixa_sessao_id')->nullable();

            $table->index('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atendimento_pagamentos');
        Schema::dropIfExists('atendimento_exames');
        Schema::dropIfExists('atendimentos');
        Schema::dropIfExists('protocolo_sequence');
    }
};

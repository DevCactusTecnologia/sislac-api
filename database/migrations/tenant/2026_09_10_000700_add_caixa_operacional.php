<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caixa_sessoes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('unidade_id');
            $table->timestampTz('aberta_em')->useCurrent();
            $table->timestampTz('fechada_em')->nullable();
            $table->uuid('responsavel_id')->nullable();
            $table->decimal('valor_abertura', 14, 2)->default(0);
            $table->decimal('valor_fechamento', 14, 2)->nullable();
            $table->text('observacoes')->nullable();
            $table->text('status')->default('aberta');
            $table->uuid('fechado_por')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('unidade_id', 'idx_caixa_sessoes_unidade');
        });

        DB::statement("ALTER TABLE caixa_sessoes ADD CONSTRAINT caixa_sessoes_status_check CHECK (status IN ('aberta', 'fechada', 'cancelada'))");
        DB::statement('ALTER TABLE caixa_sessoes ADD CONSTRAINT caixa_sessoes_valor_abertura_check CHECK (valor_abertura >= 0)');
        DB::statement("CREATE UNIQUE INDEX uq_caixa_sessao_aberta_por_unidade ON caixa_sessoes (unidade_id) WHERE status = 'aberta'");

        Schema::create('financeiro_saidas', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('protocolo');
            $table->timestampTz('data')->useCurrent();
            $table->text('descricao')->default('');
            $table->decimal('valor', 10, 2)->default(0);
            $table->text('tipo_despesa')->default('');
            $table->text('destino_pagamento')->default('');
            $table->date('data_vencimento')->nullable();
            $table->boolean('foi_pago')->default(false);
            $table->date('data_pagamento')->nullable();
            $table->text('assinatura_protocolo')->nullable();
            $table->text('forma_pagamento')->nullable();
            $table->text('status')->default('aberta');
            $table->unsignedBigInteger('caixa_sessao_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique('protocolo', 'financeiro_saidas_protocolo_unique');
            $table->foreign('caixa_sessao_id', 'financeiro_saidas_caixa_sessao_id_foreign')
                ->references('id')
                ->on('caixa_sessoes')
                ->nullOnDelete();
            $table->index('caixa_sessao_id', 'idx_financeiro_saidas_caixa_sessao');
        });

        DB::statement("ALTER TABLE financeiro_saidas ADD CONSTRAINT financeiro_saidas_status_check CHECK (status IN ('aberta', 'paga', 'cancelada'))");
        DB::statement('ALTER TABLE financeiro_saidas ADD CONSTRAINT financeiro_saidas_valor_check CHECK (valor >= 0)');

        Schema::table('atendimento_pagamentos', function (Blueprint $table): void {
            $table->foreign('caixa_sessao_id', 'atendimento_pagamentos_caixa_sessao_id_foreign')
                ->references('id')
                ->on('caixa_sessoes')
                ->nullOnDelete();
            $table->index('caixa_sessao_id', 'idx_atendimento_pagamentos_caixa_sessao');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.caixa_touch_updated_at()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                NEW.updated_at := now();
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_caixa_touch_updated_at
            BEFORE UPDATE ON public.caixa_sessoes
            FOR EACH ROW
            EXECUTE FUNCTION public.caixa_touch_updated_at();

            CREATE OR REPLACE FUNCTION public.caixa_block_session_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                RAISE EXCEPTION 'sessão de caixa não pode ser excluída';
            END;
            $$;

            CREATE TRIGGER trg_caixa_block_session_delete
            BEFORE DELETE ON public.caixa_sessoes
            FOR EACH ROW
            EXECUTE FUNCTION public.caixa_block_session_delete();

            CREATE OR REPLACE FUNCTION public.caixa_attach_pagamento()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_unidade text;
                v_sessao bigint;
            BEGIN
                IF NEW.caixa_sessao_id IS NOT NULL
                   OR NEW.tipo NOT IN ('Dinheiro', 'PIX') THEN
                    RETURN NEW;
                END IF;

                SELECT a.unidade_id
                  INTO v_unidade
                  FROM public.atendimentos AS a
                 WHERE a.id = NEW.atendimento_id;

                IF v_unidade IS NULL OR btrim(v_unidade) = '' THEN
                    RETURN NEW;
                END IF;

                SELECT c.id
                  INTO v_sessao
                  FROM public.caixa_sessoes AS c
                 WHERE c.unidade_id = v_unidade
                   AND c.status = 'aberta'
                 ORDER BY c.aberta_em DESC, c.id DESC
                 LIMIT 1
                 FOR SHARE;

                IF v_sessao IS NOT NULL THEN
                    NEW.caixa_sessao_id := v_sessao;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_caixa_attach_pagamento
            BEFORE INSERT ON public.atendimento_pagamentos
            FOR EACH ROW
            EXECUTE FUNCTION public.caixa_attach_pagamento();

            CREATE OR REPLACE FUNCTION public.caixa_guard_sessao_pagamento()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_status text;
            BEGIN
                IF NEW.caixa_sessao_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE'
                   AND NEW.caixa_sessao_id IS NOT DISTINCT FROM OLD.caixa_sessao_id THEN
                    RETURN NEW;
                END IF;

                SELECT c.status
                  INTO v_status
                  FROM public.caixa_sessoes AS c
                 WHERE c.id = NEW.caixa_sessao_id
                 FOR SHARE;

                IF v_status IS DISTINCT FROM 'aberta' THEN
                    RAISE EXCEPTION 'caixa_fechado: sessão não aceita novos movimentos';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_caixa_guard_pagamento
            BEFORE INSERT OR UPDATE OF caixa_sessao_id ON public.atendimento_pagamentos
            FOR EACH ROW
            EXECUTE FUNCTION public.caixa_guard_sessao_pagamento();

            CREATE OR REPLACE FUNCTION public.caixa_attach_saida()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_quantidade integer;
                v_sessao bigint;
            BEGIN
                IF NEW.caixa_sessao_id IS NOT NULL
                   OR NEW.foi_pago IS DISTINCT FROM true
                   OR NEW.forma_pagamento NOT IN ('Dinheiro', 'PIX') THEN
                    RETURN NEW;
                END IF;

                SELECT count(*)::integer, min(c.id)
                  INTO v_quantidade, v_sessao
                  FROM public.caixa_sessoes AS c
                 WHERE c.status = 'aberta';

                IF v_quantidade <> 1 OR v_sessao IS NULL THEN
                    RETURN NEW;
                END IF;

                PERFORM 1
                  FROM public.caixa_sessoes AS c
                 WHERE c.id = v_sessao
                   AND c.status = 'aberta'
                 FOR SHARE;

                IF FOUND THEN
                    NEW.caixa_sessao_id := v_sessao;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_caixa_attach_saida
            BEFORE INSERT OR UPDATE ON public.financeiro_saidas
            FOR EACH ROW
            EXECUTE FUNCTION public.caixa_attach_saida();

            CREATE OR REPLACE FUNCTION public.caixa_guard_sessao_saida()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_status text;
            BEGIN
                IF NEW.caixa_sessao_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE'
                   AND NEW.caixa_sessao_id IS NOT DISTINCT FROM OLD.caixa_sessao_id THEN
                    RETURN NEW;
                END IF;

                SELECT c.status
                  INTO v_status
                  FROM public.caixa_sessoes AS c
                 WHERE c.id = NEW.caixa_sessao_id
                 FOR SHARE;

                IF v_status IS DISTINCT FROM 'aberta' THEN
                    RAISE EXCEPTION 'caixa_fechado: sessão não aceita novos movimentos';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_caixa_guard_saida
            BEFORE INSERT OR UPDATE OF caixa_sessao_id ON public.financeiro_saidas
            FOR EACH ROW
            EXECUTE FUNCTION public.caixa_guard_sessao_saida();

            CREATE OR REPLACE FUNCTION public.financeiro_block_saida_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                RAISE EXCEPTION 'saída financeira não pode ser excluída; use estorno';
            END;
            $$;

            CREATE TRIGGER trg_financeiro_block_saida_delete
            BEFORE DELETE ON public.financeiro_saidas
            FOR EACH ROW
            EXECUTE FUNCTION public.financeiro_block_saida_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_financeiro_block_saida_delete ON public.financeiro_saidas;
            DROP FUNCTION IF EXISTS public.financeiro_block_saida_delete();
            DROP TRIGGER IF EXISTS trg_caixa_guard_saida ON public.financeiro_saidas;
            DROP FUNCTION IF EXISTS public.caixa_guard_sessao_saida();
            DROP TRIGGER IF EXISTS trg_caixa_attach_saida ON public.financeiro_saidas;
            DROP FUNCTION IF EXISTS public.caixa_attach_saida();
            DROP TRIGGER IF EXISTS trg_caixa_guard_pagamento ON public.atendimento_pagamentos;
            DROP FUNCTION IF EXISTS public.caixa_guard_sessao_pagamento();
            DROP TRIGGER IF EXISTS trg_caixa_attach_pagamento ON public.atendimento_pagamentos;
            DROP FUNCTION IF EXISTS public.caixa_attach_pagamento();
            DROP TRIGGER IF EXISTS trg_caixa_block_session_delete ON public.caixa_sessoes;
            DROP FUNCTION IF EXISTS public.caixa_block_session_delete();
            DROP TRIGGER IF EXISTS trg_caixa_touch_updated_at ON public.caixa_sessoes;
            DROP FUNCTION IF EXISTS public.caixa_touch_updated_at();
        SQL);

        Schema::table('atendimento_pagamentos', function (Blueprint $table): void {
            $table->dropForeign('atendimento_pagamentos_caixa_sessao_id_foreign');
            $table->dropIndex('idx_atendimento_pagamentos_caixa_sessao');
        });

        Schema::dropIfExists('financeiro_saidas');
        Schema::dropIfExists('caixa_sessoes');
    }
};

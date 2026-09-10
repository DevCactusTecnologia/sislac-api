<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financeiro_estornos', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('origem_tipo');
            $table->unsignedBigInteger('origem_id');
            $table->text('motivo');
            $table->decimal('valor', 14, 2);
            $table->uuid('criado_por')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['origem_tipo', 'origem_id'], 'uq_financeiro_estornos_origem');
            $table->index('criado_por', 'idx_fk_financeiro_estornos_criado_por');
        });

        DB::statement("ALTER TABLE financeiro_estornos ADD CONSTRAINT financeiro_estornos_origem_tipo_check CHECK (origem_tipo IN ('pagamento', 'fatura', 'saida'))");
        DB::statement("ALTER TABLE financeiro_estornos ADD CONSTRAINT financeiro_estornos_motivo_check CHECK (length(btrim(motivo)) > 0)");
        DB::statement('ALTER TABLE financeiro_estornos ADD CONSTRAINT financeiro_estornos_valor_check CHECK (valor > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.financeiro_protect_estorno()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                RAISE EXCEPTION 'estorno financeiro é append-only';
            END;
            $$;

            CREATE TRIGGER trg_financeiro_estornos_append_only
            BEFORE UPDATE OR DELETE ON public.financeiro_estornos
            FOR EACH ROW
            EXECUTE FUNCTION public.financeiro_protect_estorno();

            CREATE OR REPLACE FUNCTION public.financeiro_block_pagamento_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                RAISE EXCEPTION 'pagamento não pode ser excluído; use estorno';
            END;
            $$;

            CREATE TRIGGER trg_financeiro_block_pagamento_delete
            BEFORE DELETE ON public.atendimento_pagamentos
            FOR EACH ROW
            EXECUTE FUNCTION public.financeiro_block_pagamento_delete();

            CREATE OR REPLACE FUNCTION public.financeiro_validate_pagamento_insert()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_devido numeric(14, 2);
                v_pago numeric(14, 2);
                v_saldo numeric(14, 2);
            BEGIN
                IF NEW.valor <= 0 THEN
                    RAISE EXCEPTION 'valor do pagamento deve ser maior que zero';
                END IF;

                PERFORM 1
                FROM public.atendimentos
                WHERE id = NEW.atendimento_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF COALESCE(NEW.status_pagamento, 'efetuado') = 'estornado' THEN
                    RETURN NEW;
                END IF;

                SELECT COALESCE(SUM(e.valor), 0)::numeric(14, 2)
                  INTO v_devido
                  FROM public.atendimento_exames AS e
                 WHERE e.atendimento_id = NEW.atendimento_id
                   AND e.status <> 'cancelado'
                   AND COALESCE(e.cobranca_destino, 'paciente') <> 'convenio';

                SELECT COALESCE(SUM(p.valor), 0)::numeric(14, 2)
                  INTO v_pago
                  FROM public.atendimento_pagamentos AS p
                 WHERE p.atendimento_id = NEW.atendimento_id
                   AND COALESCE(p.status_pagamento, 'efetuado') <> 'estornado';

                v_saldo := GREATEST(v_devido - v_pago, 0)::numeric(14, 2);

                IF NEW.valor > v_saldo THEN
                    RAISE EXCEPTION 'valor do pagamento excede o saldo atual do atendimento';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_financeiro_validate_pagamento_insert
            BEFORE INSERT ON public.atendimento_pagamentos
            FOR EACH ROW
            EXECUTE FUNCTION public.financeiro_validate_pagamento_insert();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_financeiro_validate_pagamento_insert ON public.atendimento_pagamentos;
            DROP FUNCTION IF EXISTS public.financeiro_validate_pagamento_insert();
            DROP TRIGGER IF EXISTS trg_financeiro_block_pagamento_delete ON public.atendimento_pagamentos;
            DROP FUNCTION IF EXISTS public.financeiro_block_pagamento_delete();
            DROP TRIGGER IF EXISTS trg_financeiro_estornos_append_only ON public.financeiro_estornos;
            DROP FUNCTION IF EXISTS public.financeiro_protect_estorno();
        SQL);

        Schema::dropIfExists('financeiro_estornos');
    }
};

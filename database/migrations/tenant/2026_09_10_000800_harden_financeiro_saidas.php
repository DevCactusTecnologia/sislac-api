<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.financeiro_saidas DROP CONSTRAINT IF EXISTS financeiro_saidas_valor_check');
        DB::statement('ALTER TABLE public.financeiro_saidas ADD CONSTRAINT financeiro_saidas_valor_check CHECK (valor > 0)');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_caixa_attach_saida ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_10_financeiro_saida_prepare_state ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_20_financeiro_saida_assign_protocolo ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_30_caixa_attach_saida ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_40_financeiro_saida_protect_update ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_50_financeiro_saida_touch_updated_at ON public.financeiro_saidas;

            CREATE OR REPLACE FUNCTION public.next_financeiro_saida_protocolo(p_data timestamptz)
            RETURNS text
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_ano integer := EXTRACT(YEAR FROM COALESCE(p_data, now()))::integer;
                v_numero bigint;
            BEGIN
                INSERT INTO public.protocolo_sequence (prefixo, ano, ultimo_numero)
                VALUES ('SAI', v_ano, 1)
                ON CONFLICT (prefixo, ano) DO UPDATE
                   SET ultimo_numero = public.protocolo_sequence.ultimo_numero + 1,
                       updated_at = now()
                RETURNING ultimo_numero INTO v_numero;

                RETURN 'SAI-' || v_ano::text || '-' || lpad(v_numero::text, 7, '0');
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.financeiro_saida_prepare_state()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                IF NEW.status = 'paga' THEN
                    NEW.foi_pago := true;
                    NEW.data_pagamento := COALESCE(NEW.data_pagamento, CURRENT_DATE);
                ELSIF NEW.status = 'aberta' THEN
                    NEW.foi_pago := false;
                    NEW.data_pagamento := NULL;
                ELSE
                    NEW.foi_pago := false;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.financeiro_saida_assign_protocolo()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                NEW.protocolo := public.next_financeiro_saida_protocolo(NEW.data);
                NEW.assinatura_protocolo := NULL;

                RETURN NEW;
            END;
            $$;

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
                   OR NEW.status IS DISTINCT FROM 'paga'
                   OR NEW.forma_pagamento NOT IN ('Dinheiro', 'PIX') THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.status = 'paga' THEN
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

            CREATE OR REPLACE FUNCTION public.financeiro_saida_protect_update()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                IF NEW.protocolo IS DISTINCT FROM OLD.protocolo THEN
                    RAISE EXCEPTION 'protocolo da saída é imutável'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.assinatura_protocolo IS NOT NULL
                   AND NEW.assinatura_protocolo IS DISTINCT FROM OLD.assinatura_protocolo THEN
                    RAISE EXCEPTION 'assinatura da saída é imutável'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'cancelada'
                   AND NEW.status IS DISTINCT FROM 'cancelada' THEN
                    RAISE EXCEPTION 'saída financeira terminal é imutável; use estorno'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'paga'
                   AND NEW.status NOT IN ('paga', 'cancelada') THEN
                    RAISE EXCEPTION 'saída financeira terminal é imutável; use estorno'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.status = 'cancelada'
                   AND OLD.status IS DISTINCT FROM 'cancelada'
                   AND NOT EXISTS (
                       SELECT 1
                         FROM public.financeiro_estornos AS e
                        WHERE e.origem_tipo = 'saida'
                          AND e.origem_id = OLD.id
                   ) THEN
                    RAISE EXCEPTION 'saída só pode ser cancelada por estorno'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('paga', 'cancelada')
                   AND (
                       NEW.data IS DISTINCT FROM OLD.data
                       OR NEW.descricao IS DISTINCT FROM OLD.descricao
                       OR NEW.valor IS DISTINCT FROM OLD.valor
                       OR NEW.tipo_despesa IS DISTINCT FROM OLD.tipo_despesa
                       OR NEW.destino_pagamento IS DISTINCT FROM OLD.destino_pagamento
                       OR NEW.data_vencimento IS DISTINCT FROM OLD.data_vencimento
                       OR NEW.data_pagamento IS DISTINCT FROM OLD.data_pagamento
                       OR NEW.forma_pagamento IS DISTINCT FROM OLD.forma_pagamento
                       OR NEW.caixa_sessao_id IS DISTINCT FROM OLD.caixa_sessao_id
                   ) THEN
                    RAISE EXCEPTION 'saída financeira terminal é imutável; use estorno'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.financeiro_saida_touch_updated_at()
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

            CREATE TRIGGER trg_10_financeiro_saida_prepare_state
            BEFORE INSERT OR UPDATE ON public.financeiro_saidas
            FOR EACH ROW
            EXECUTE FUNCTION public.financeiro_saida_prepare_state();

            CREATE TRIGGER trg_20_financeiro_saida_assign_protocolo
            BEFORE INSERT ON public.financeiro_saidas
            FOR EACH ROW
            EXECUTE FUNCTION public.financeiro_saida_assign_protocolo();

            CREATE TRIGGER trg_30_caixa_attach_saida
            BEFORE INSERT OR UPDATE ON public.financeiro_saidas
            FOR EACH ROW
            EXECUTE FUNCTION public.caixa_attach_saida();

            CREATE TRIGGER trg_40_financeiro_saida_protect_update
            BEFORE UPDATE ON public.financeiro_saidas
            FOR EACH ROW
            EXECUTE FUNCTION public.financeiro_saida_protect_update();

            CREATE TRIGGER trg_50_financeiro_saida_touch_updated_at
            BEFORE UPDATE ON public.financeiro_saidas
            FOR EACH ROW
            EXECUTE FUNCTION public.financeiro_saida_touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_50_financeiro_saida_touch_updated_at ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_40_financeiro_saida_protect_update ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_30_caixa_attach_saida ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_20_financeiro_saida_assign_protocolo ON public.financeiro_saidas;
            DROP TRIGGER IF EXISTS trg_10_financeiro_saida_prepare_state ON public.financeiro_saidas;

            DROP FUNCTION IF EXISTS public.financeiro_saida_touch_updated_at();
            DROP FUNCTION IF EXISTS public.financeiro_saida_protect_update();
            DROP FUNCTION IF EXISTS public.financeiro_saida_assign_protocolo();
            DROP FUNCTION IF EXISTS public.financeiro_saida_prepare_state();
            DROP FUNCTION IF EXISTS public.next_financeiro_saida_protocolo(timestamptz);

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
        SQL);

        DB::statement('ALTER TABLE public.financeiro_saidas DROP CONSTRAINT IF EXISTS financeiro_saidas_valor_check');
        DB::statement('ALTER TABLE public.financeiro_saidas ADD CONSTRAINT financeiro_saidas_valor_check CHECK (valor >= 0)');
    }
};

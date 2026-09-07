<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE atendimento_protocol_counter (
                name text PRIMARY KEY,
                last_value bigint NOT NULL CHECK (last_value > 0)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX atendimentos_idempotency_key_unique
            ON atendimentos (idempotency_key)
            WHERE idempotency_key IS NOT NULL
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION next_atendimento_protocolo()
            RETURNS text
            LANGUAGE plpgsql
            AS $$
            DECLARE
                next_number bigint;
                candidate text;
            BEGIN
                LOOP
                    INSERT INTO atendimento_protocol_counter (name, last_value)
                    VALUES ('atendimento', 1)
                    ON CONFLICT (name) DO UPDATE
                    SET last_value = atendimento_protocol_counter.last_value + 1
                    RETURNING last_value INTO next_number;

                    candidate := lpad(next_number::text, 7, '0');

                    IF NOT EXISTS (
                        SELECT 1 FROM atendimentos WHERE protocolo = candidate
                    ) THEN
                        RETURN candidate;
                    END IF;
                END LOOP;
            END;
            $$;

            CREATE OR REPLACE FUNCTION assign_atendimento_protocolo()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.protocolo IS NULL OR btrim(NEW.protocolo) = '' THEN
                    NEW.protocolo := next_atendimento_protocolo();
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION protect_atendimento_protocolo()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.protocolo IS DISTINCT FROM OLD.protocolo THEN
                    RAISE EXCEPTION 'protocolo do atendimento é imutável';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_atendimento_assign_protocolo
            BEFORE INSERT ON atendimentos
            FOR EACH ROW EXECUTE FUNCTION assign_atendimento_protocolo();

            CREATE TRIGGER trg_protect_atendimento_protocolo
            BEFORE UPDATE OF protocolo ON atendimentos
            FOR EACH ROW EXECUTE FUNCTION protect_atendimento_protocolo();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recompute_atendimento_completo(target_atendimento_id bigint)
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            DECLARE
                total_exames integer;
                total_cancelados integer;
                total_finalizados integer;
                total_em_analise integer;
                total_digitados integer;
                total_coletados integer;
                total_pendentes integer;
                ativos integer;
                ativos_convenio integer;
                novo_status_at text;
                novo_status_pg text;
                total_valor numeric(14,2);
                total_pago numeric(14,2);
                v_subtotal numeric(14,2) := 0;
                v_total numeric(14,2) := 0;
                v_delta numeric(14,2);
            BEGIN
                SELECT
                    count(*),
                    count(*) FILTER (WHERE status = 'cancelado'),
                    count(*) FILTER (WHERE status = 'finalizado'),
                    count(*) FILTER (WHERE status IN ('em_analise', 'analisado')),
                    count(*) FILTER (WHERE status = 'digitado'),
                    count(*) FILTER (WHERE status = 'coletado'),
                    count(*) FILTER (WHERE COALESCE(status, 'pendente') = 'pendente'),
                    COALESCE(SUM(COALESCE(valor_original, valor)) FILTER (WHERE COALESCE(status, '') <> 'cancelado'), 0),
                    COALESCE(SUM(valor) FILTER (WHERE COALESCE(status, '') <> 'cancelado'), 0),
                    COALESCE(SUM(valor) FILTER (
                        WHERE COALESCE(status, '') <> 'cancelado'
                          AND COALESCE(cobranca_destino, 'paciente') <> 'convenio'
                    ), 0),
                    count(*) FILTER (
                        WHERE COALESCE(status, '') <> 'cancelado'
                          AND COALESCE(cobranca_destino, 'paciente') = 'convenio'
                    )
                INTO
                    total_exames,
                    total_cancelados,
                    total_finalizados,
                    total_em_analise,
                    total_digitados,
                    total_coletados,
                    total_pendentes,
                    v_subtotal,
                    v_total,
                    total_valor,
                    ativos_convenio
                FROM atendimento_exames
                WHERE atendimento_id = target_atendimento_id;

                ativos := total_exames - total_cancelados;

                IF total_exames = 0 THEN
                    novo_status_at := 'Pedido Realizado';
                ELSIF total_cancelados = total_exames THEN
                    novo_status_at := 'Cancelado';
                ELSIF total_pendentes >= ativos THEN
                    novo_status_at := 'Pedido Realizado';
                ELSIF total_pendentes > 0 THEN
                    IF (total_finalizados + total_em_analise + total_digitados) > 0 THEN
                        novo_status_at := 'Amostra Analisada';
                    ELSE
                        novo_status_at := 'Amostra Coletada';
                    END IF;
                ELSIF total_finalizados = ativos AND ativos > 0 THEN
                    novo_status_at := 'Resultado Liberado';
                ELSIF (total_finalizados + total_em_analise + total_digitados) = ativos
                    AND (total_em_analise + total_digitados) > 0 THEN
                    novo_status_at := 'Amostra Analisada';
                ELSIF (total_finalizados + total_em_analise + total_digitados + total_coletados) > 0 THEN
                    novo_status_at := 'Amostra Coletada';
                ELSE
                    novo_status_at := 'Pedido Realizado';
                END IF;

                SELECT COALESCE(SUM(valor), 0)
                INTO total_pago
                FROM atendimento_pagamentos
                WHERE atendimento_id = target_atendimento_id
                  AND COALESCE(status_pagamento, 'efetuado') <> 'estornado';

                IF total_cancelados = total_exames AND total_exames > 0 THEN
                    novo_status_pg := 'Pagamento cancelado';
                ELSIF ativos > 0 AND ativos_convenio = ativos AND total_pago = 0 THEN
                    novo_status_pg := 'Faturado ao convênio';
                ELSIF total_valor = 0 OR total_pago >= total_valor THEN
                    novo_status_pg := 'Pagamento efetuado';
                ELSIF total_pago > 0 THEN
                    novo_status_pg := 'Pagamento parcial';
                ELSE
                    novo_status_pg := 'Pagamento pendente';
                END IF;

                v_delta := v_subtotal - v_total;

                UPDATE atendimentos
                SET status_atendimento = novo_status_at,
                    status_pagamento = novo_status_pg,
                    subtotal = v_subtotal,
                    total = v_total,
                    desconto_total = CASE WHEN v_delta > 0 THEN v_delta ELSE 0 END,
                    acrescimo_total = CASE WHEN v_delta < 0 THEN -v_delta ELSE 0 END,
                    updated_at = now()
                WHERE id = target_atendimento_id
                  AND (
                      status_atendimento IS DISTINCT FROM novo_status_at
                      OR status_pagamento IS DISTINCT FROM novo_status_pg
                      OR subtotal IS DISTINCT FROM v_subtotal
                      OR total IS DISTINCT FROM v_total
                      OR desconto_total IS DISTINCT FROM CASE WHEN v_delta > 0 THEN v_delta ELSE 0 END
                      OR acrescimo_total IS DISTINCT FROM CASE WHEN v_delta < 0 THEN -v_delta ELSE 0 END
                  );
            END;
            $$;

            CREATE OR REPLACE FUNCTION recompute_atendimento_from_child()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                old_id bigint;
                new_id bigint;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    old_id := OLD.atendimento_id;
                END IF;

                IF TG_OP <> 'DELETE' THEN
                    new_id := NEW.atendimento_id;
                END IF;

                IF old_id IS NOT NULL THEN
                    PERFORM recompute_atendimento_completo(old_id);
                END IF;

                IF new_id IS NOT NULL AND new_id IS DISTINCT FROM old_id THEN
                    PERFORM recompute_atendimento_completo(new_id);
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$;

            CREATE TRIGGER trg_recompute_atendimento_on_exame
            AFTER INSERT OR UPDATE OR DELETE ON atendimento_exames
            FOR EACH ROW EXECUTE FUNCTION recompute_atendimento_from_child();

            CREATE TRIGGER trg_recompute_atendimento_on_pagamento
            AFTER INSERT OR UPDATE OR DELETE ON atendimento_pagamentos
            FOR EACH ROW EXECUTE FUNCTION recompute_atendimento_from_child();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_recompute_atendimento_on_pagamento ON atendimento_pagamentos');
        DB::statement('DROP TRIGGER IF EXISTS trg_recompute_atendimento_on_exame ON atendimento_exames');
        DB::statement('DROP FUNCTION IF EXISTS recompute_atendimento_from_child()');
        DB::statement('DROP FUNCTION IF EXISTS recompute_atendimento_completo(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS trg_protect_atendimento_protocolo ON atendimentos');
        DB::statement('DROP TRIGGER IF EXISTS trg_atendimento_assign_protocolo ON atendimentos');
        DB::statement('DROP FUNCTION IF EXISTS protect_atendimento_protocolo()');
        DB::statement('DROP FUNCTION IF EXISTS assign_atendimento_protocolo()');
        DB::statement('DROP FUNCTION IF EXISTS next_atendimento_protocolo()');
        DB::statement('DROP INDEX IF EXISTS atendimentos_idempotency_key_unique');
        DB::statement('DROP TABLE IF EXISTS atendimento_protocol_counter');
    }
};

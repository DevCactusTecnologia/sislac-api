<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
                    INSERT INTO protocolo_sequence (prefixo, ano, ultimo_numero)
                    VALUES ('ATD', 0, 1)
                    ON CONFLICT (prefixo, ano) DO UPDATE
                    SET ultimo_numero = protocolo_sequence.ultimo_numero + 1,
                        updated_at = now()
                    RETURNING ultimo_numero INTO next_number;

                    candidate := lpad(next_number::text, 7, '0');

                    EXIT WHEN NOT EXISTS (
                        SELECT 1
                        FROM atendimentos
                        WHERE protocolo = candidate
                    );
                END LOOP;

                RETURN candidate;
            END;
            $$;

            CREATE OR REPLACE FUNCTION assign_atendimento_protocolo()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                NEW.protocolo := next_atendimento_protocolo();
                NEW.assinatura_protocolo := NULL;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION protect_atendimento_protocolo()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.protocolo IS DISTINCT FROM OLD.protocolo THEN
                    RAISE EXCEPTION 'protocolo do atendimento é imutável'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.assinatura_protocolo IS NOT NULL
                    AND NEW.assinatura_protocolo IS DISTINCT FROM OLD.assinatura_protocolo THEN
                    RAISE EXCEPTION 'assinatura do protocolo é imutável'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_atendimento_assign_protocolo
            BEFORE INSERT ON atendimentos
            FOR EACH ROW EXECUTE FUNCTION assign_atendimento_protocolo();

            CREATE TRIGGER trg_protect_atendimento_protocolo
            BEFORE UPDATE ON atendimentos
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
                total_devido_paciente numeric(14, 2);
                total_pago numeric(14, 2);
                v_subtotal numeric(14, 2) := 0;
                v_total numeric(14, 2) := 0;
                v_delta numeric(14, 2);
            BEGIN
                SELECT
                    count(*),
                    count(*) FILTER (WHERE status = 'cancelado'),
                    count(*) FILTER (WHERE status = 'finalizado'),
                    count(*) FILTER (WHERE status IN ('em_analise', 'analisado')),
                    count(*) FILTER (WHERE status = 'digitado'),
                    count(*) FILTER (WHERE status = 'coletado'),
                    count(*) FILTER (WHERE COALESCE(status, 'pendente') = 'pendente'),
                    COALESCE(SUM(COALESCE(valor_original, valor)) FILTER (
                        WHERE COALESCE(status, '') <> 'cancelado'
                    ), 0),
                    COALESCE(SUM(valor) FILTER (
                        WHERE COALESCE(status, '') <> 'cancelado'
                    ), 0),
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
                    total_devido_paciente,
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
                ELSIF total_devido_paciente = 0 OR total_pago >= total_devido_paciente THEN
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

            CREATE OR REPLACE FUNCTION recompute_atendimento_from_exame()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM recompute_atendimento_completo(OLD.atendimento_id);
                    RETURN OLD;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.atendimento_id = OLD.atendimento_id
                       AND NEW.status IS NOT DISTINCT FROM OLD.status
                       AND NEW.valor IS NOT DISTINCT FROM OLD.valor
                       AND NEW.valor_original IS NOT DISTINCT FROM OLD.valor_original
                       AND NEW.cobranca_destino IS NOT DISTINCT FROM OLD.cobranca_destino THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.atendimento_id IS DISTINCT FROM OLD.atendimento_id THEN
                        PERFORM recompute_atendimento_completo(OLD.atendimento_id);
                    END IF;
                END IF;

                PERFORM recompute_atendimento_completo(NEW.atendimento_id);
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION recompute_atendimento_from_pagamento()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM recompute_atendimento_completo(OLD.atendimento_id);
                    RETURN OLD;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.atendimento_id = OLD.atendimento_id
                       AND NEW.valor IS NOT DISTINCT FROM OLD.valor
                       AND NEW.status_pagamento IS NOT DISTINCT FROM OLD.status_pagamento THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.atendimento_id IS DISTINCT FROM OLD.atendimento_id THEN
                        PERFORM recompute_atendimento_completo(OLD.atendimento_id);
                    END IF;
                END IF;

                PERFORM recompute_atendimento_completo(NEW.atendimento_id);
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_recompute_atendimento_on_exame
            AFTER INSERT OR UPDATE OR DELETE ON atendimento_exames
            FOR EACH ROW EXECUTE FUNCTION recompute_atendimento_from_exame();

            CREATE TRIGGER trg_recompute_atendimento_on_pagamento
            AFTER INSERT OR UPDATE OR DELETE ON atendimento_pagamentos
            FOR EACH ROW EXECUTE FUNCTION recompute_atendimento_from_pagamento();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_atendimento_change()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_old jsonb;
                v_new jsonb;
                v_atendimento_id bigint;
                v_registro_id bigint;
                v_protocolo text := '';
                v_paciente_nome text := '';
                v_exame_nome text := '';
                v_acao text;
                v_changed_by uuid;
                v_changed_by_email text := '';
                v_justificativa text := '';
                v_user_setting text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    v_old := to_jsonb(OLD);
                END IF;

                IF TG_OP <> 'DELETE' THEN
                    v_new := to_jsonb(NEW);
                END IF;

                IF TG_TABLE_NAME = 'atendimentos' AND TG_OP = 'UPDATE' THEN
                    IF (v_new - ARRAY[
                        'status_atendimento',
                        'status_pagamento',
                        'subtotal',
                        'desconto_total',
                        'acrescimo_total',
                        'total',
                        'updated_at'
                    ]) = (v_old - ARRAY[
                        'status_atendimento',
                        'status_pagamento',
                        'subtotal',
                        'desconto_total',
                        'acrescimo_total',
                        'total',
                        'updated_at'
                    ]) THEN
                        RETURN NULL;
                    END IF;
                END IF;

                IF TG_TABLE_NAME = 'atendimentos' THEN
                    v_atendimento_id := COALESCE(
                        NULLIF(v_new->>'id', '')::bigint,
                        NULLIF(v_old->>'id', '')::bigint
                    );
                    v_registro_id := v_atendimento_id;
                    v_protocolo := COALESCE(v_new->>'protocolo', v_old->>'protocolo', '');
                    v_paciente_nome := COALESCE(v_new->>'paciente_nome', v_old->>'paciente_nome', '');
                ELSE
                    v_atendimento_id := COALESCE(
                        NULLIF(v_new->>'atendimento_id', '')::bigint,
                        NULLIF(v_old->>'atendimento_id', '')::bigint
                    );
                    v_registro_id := COALESCE(
                        NULLIF(v_new->>'id', '')::bigint,
                        NULLIF(v_old->>'id', '')::bigint
                    );
                    v_exame_nome := CASE
                        WHEN TG_TABLE_NAME = 'atendimento_exames'
                        THEN COALESCE(v_new->>'nome_exame', v_old->>'nome_exame', '')
                        ELSE ''
                    END;

                    SELECT protocolo, paciente_nome
                    INTO v_protocolo, v_paciente_nome
                    FROM atendimentos
                    WHERE id = v_atendimento_id;

                    v_protocolo := COALESCE(v_protocolo, '');
                    v_paciente_nome := COALESCE(v_paciente_nome, '');
                END IF;

                v_user_setting := NULLIF(current_setting('app.audit_user_id', true), '');
                IF v_user_setting IS NOT NULL THEN
                    v_changed_by := v_user_setting::uuid;
                END IF;

                v_changed_by_email := COALESCE(
                    NULLIF(current_setting('app.audit_user_email', true), ''),
                    ''
                );
                v_justificativa := COALESCE(
                    NULLIF(current_setting('app.audit_justificativa', true), ''),
                    ''
                );

                v_acao := CASE TG_OP
                    WHEN 'INSERT' THEN 'Criado'
                    WHEN 'UPDATE' THEN 'Atualizado'
                    WHEN 'DELETE' THEN 'Excluído'
                    ELSE TG_OP
                END;

                INSERT INTO atendimento_audit (
                    entidade,
                    operacao,
                    acao,
                    atendimento_id,
                    registro_id,
                    protocolo,
                    paciente_nome,
                    exame_nome,
                    old_value,
                    new_value,
                    changed_by,
                    changed_by_email,
                    justificativa
                ) VALUES (
                    TG_TABLE_NAME,
                    TG_OP,
                    v_acao,
                    v_atendimento_id,
                    v_registro_id,
                    v_protocolo,
                    v_paciente_nome,
                    v_exame_nome,
                    v_old,
                    v_new,
                    v_changed_by,
                    v_changed_by_email,
                    v_justificativa
                );

                RETURN NULL;
            END;
            $$;

            CREATE OR REPLACE FUNCTION protect_atendimento_audit()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'auditoria de atendimento é append-only'
                    USING ERRCODE = '23514';
            END;
            $$;

            CREATE TRIGGER trg_audit_atendimentos
            AFTER INSERT OR UPDATE OR DELETE ON atendimentos
            FOR EACH ROW EXECUTE FUNCTION audit_atendimento_change();

            CREATE TRIGGER trg_audit_atendimento_exames
            AFTER INSERT OR UPDATE OR DELETE ON atendimento_exames
            FOR EACH ROW EXECUTE FUNCTION audit_atendimento_change();

            CREATE TRIGGER trg_audit_atendimento_pagamentos
            AFTER INSERT OR UPDATE OR DELETE ON atendimento_pagamentos
            FOR EACH ROW EXECUTE FUNCTION audit_atendimento_change();

            CREATE TRIGGER trg_protect_atendimento_audit
            BEFORE UPDATE OR DELETE ON atendimento_audit
            FOR EACH ROW EXECUTE FUNCTION protect_atendimento_audit();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_protect_atendimento_audit ON atendimento_audit');
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_atendimento_pagamentos ON atendimento_pagamentos');
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_atendimento_exames ON atendimento_exames');
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_atendimentos ON atendimentos');
        DB::statement('DROP FUNCTION IF EXISTS protect_atendimento_audit()');
        DB::statement('DROP FUNCTION IF EXISTS audit_atendimento_change()');
        DB::statement('DROP TRIGGER IF EXISTS trg_recompute_atendimento_on_pagamento ON atendimento_pagamentos');
        DB::statement('DROP TRIGGER IF EXISTS trg_recompute_atendimento_on_exame ON atendimento_exames');
        DB::statement('DROP FUNCTION IF EXISTS recompute_atendimento_from_pagamento()');
        DB::statement('DROP FUNCTION IF EXISTS recompute_atendimento_from_exame()');
        DB::statement('DROP FUNCTION IF EXISTS recompute_atendimento_completo(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS trg_protect_atendimento_protocolo ON atendimentos');
        DB::statement('DROP TRIGGER IF EXISTS trg_atendimento_assign_protocolo ON atendimentos');
        DB::statement('DROP FUNCTION IF EXISTS protect_atendimento_protocolo()');
        DB::statement('DROP FUNCTION IF EXISTS assign_atendimento_protocolo()');
        DB::statement('DROP FUNCTION IF EXISTS next_atendimento_protocolo()');
        DB::statement('DROP INDEX IF EXISTS atendimentos_idempotency_key_unique');
    }
};

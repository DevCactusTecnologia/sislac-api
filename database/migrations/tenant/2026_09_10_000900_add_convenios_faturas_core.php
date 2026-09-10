<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenios', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('codigo')->nullable()->unique();
            $table->text('nome');
            $table->text('registro_ans')->default('');
            $table->text('tipo')->default('Saúde');
            $table->text('tabela')->default('Própria');
            $table->integer('dias_retorno')->default(0);
            $table->boolean('ativo')->default(true);
            $table->boolean('libera_fluxo_sem_pagamento')->default(false);
            $table->integer('prazo_faturamento_dias')->default(30);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE convenios ADD CONSTRAINT convenios_tipo_check CHECK (tipo IN ('Saúde', 'Odontológico', 'Ocupacional'))");
        DB::statement("ALTER TABLE convenios ADD CONSTRAINT convenios_tabela_check CHECK (tabela IN ('CBHPM', 'TUSS', 'Própria'))");
        DB::statement('ALTER TABLE convenios ADD CONSTRAINT convenios_dias_retorno_check CHECK (dias_retorno >= 0)');
        DB::statement('ALTER TABLE convenios ADD CONSTRAINT convenios_prazo_faturamento_check CHECK (prazo_faturamento_dias >= 0)');

        Schema::create('convenio_faturas', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('convenio_id');
            $table->text('codigo')->unique();
            $table->date('periodo_inicio');
            $table->date('periodo_fim');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('desconto', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('status')->default('aberta');
            $table->text('forma_pagamento')->default('');
            $table->date('data_pagamento')->nullable();
            $table->text('observacao')->default('');
            $table->text('assinatura_protocolo')->nullable();
            $table->timestampTz('cancelada_em')->nullable();
            $table->uuid('cancelada_por')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('convenio_id')->references('id')->on('convenios')->restrictOnDelete();
            $table->index(['convenio_id', 'status'], 'idx_convenio_faturas_convenio_status');
            $table->index(['created_at', 'id'], 'idx_convenio_faturas_cursor');
        });

        DB::statement('ALTER TABLE convenio_faturas ADD CONSTRAINT convenio_faturas_nao_particular_check CHECK (convenio_id <> 0)');
        DB::statement('ALTER TABLE convenio_faturas ADD CONSTRAINT convenio_faturas_periodo_check CHECK (periodo_inicio <= periodo_fim)');
        DB::statement('ALTER TABLE convenio_faturas ADD CONSTRAINT convenio_faturas_subtotal_check CHECK (subtotal >= 0)');
        DB::statement('ALTER TABLE convenio_faturas ADD CONSTRAINT convenio_faturas_desconto_check CHECK (desconto >= 0)');
        DB::statement('ALTER TABLE convenio_faturas ADD CONSTRAINT convenio_faturas_total_check CHECK (total >= 0)');
        DB::statement("ALTER TABLE convenio_faturas ADD CONSTRAINT convenio_faturas_status_check CHECK (status IN ('aberta', 'paga', 'cancelada'))");
        DB::statement(<<<'SQL'
            ALTER TABLE convenio_faturas
            ADD CONSTRAINT convenio_faturas_pagamento_check
            CHECK (
                status <> 'paga'
                OR (btrim(forma_pagamento) <> '' AND data_pagamento IS NOT NULL)
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE convenio_faturas
            ADD CONSTRAINT convenio_faturas_cancelamento_check
            CHECK (
                status <> 'cancelada'
                OR (cancelada_em IS NOT NULL AND btrim(COALESCE(motivo_cancelamento, '')) <> '')
            )
        SQL);

        Schema::create('convenio_fatura_itens', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('fatura_id');
            $table->unsignedBigInteger('atendimento_exame_id');
            $table->decimal('valor', 14, 2);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('fatura_id')->references('id')->on('convenio_faturas')->restrictOnDelete();
            $table->foreign('atendimento_exame_id')->references('id')->on('atendimento_exames')->restrictOnDelete();
            $table->index('fatura_id', 'idx_convenio_fatura_itens_fatura');
            $table->index('atendimento_exame_id', 'idx_convenio_fatura_itens_exame');
        });

        DB::statement('ALTER TABLE convenio_fatura_itens ADD CONSTRAINT convenio_fatura_itens_valor_check CHECK (valor >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.convenio_next_codigo()
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_numero bigint;
            BEGIN
                INSERT INTO public.protocolo_sequence AS seq (prefixo, ano, ultimo_numero)
                VALUES ('CNV', 0, 1)
                ON CONFLICT (prefixo, ano) DO UPDATE
                SET ultimo_numero = seq.ultimo_numero + 1,
                    updated_at = pg_catalog.clock_timestamp()
                RETURNING ultimo_numero INTO v_numero;

                RETURN v_numero::integer;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.convenio_assign_codigo()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                IF NEW.codigo IS NULL THEN
                    NEW.codigo := public.convenio_next_codigo();
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.convenio_protect_particular()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                IF OLD.id <> 0 THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Convênio Particular não pode ser excluído'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.id IS DISTINCT FROM 0
                   OR NEW.nome IS DISTINCT FROM 'Particular'
                   OR NEW.ativo IS DISTINCT FROM true THEN
                    RAISE EXCEPTION 'Convênio Particular não pode ser renomeado ou desativado'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_convenio_10_assign_codigo
            BEFORE INSERT ON public.convenios
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_assign_codigo();

            CREATE TRIGGER trg_convenio_20_protect_particular
            BEFORE UPDATE OR DELETE ON public.convenios
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_protect_particular();

            INSERT INTO public.convenios
                (id, codigo, nome, registro_ans, tipo, tabela, dias_retorno, ativo, libera_fluxo_sem_pagamento, prazo_faturamento_dias)
            VALUES
                (0, 0, 'Particular', '', 'Saúde', 'Própria', 0, true, false, 0);

            CREATE OR REPLACE FUNCTION public.convenio_fatura_next_codigo()
            RETURNS text
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_ano integer := pg_catalog.extract(year FROM pg_catalog.clock_timestamp())::integer;
                v_numero bigint;
                v_codigo text;
            BEGIN
                LOOP
                    INSERT INTO public.protocolo_sequence AS seq (prefixo, ano, ultimo_numero)
                    VALUES ('FAT', v_ano, 1)
                    ON CONFLICT (prefixo, ano) DO UPDATE
                    SET ultimo_numero = seq.ultimo_numero + 1,
                        updated_at = pg_catalog.clock_timestamp()
                    RETURNING ultimo_numero INTO v_numero;

                    v_codigo := 'FAT-' || v_ano::text || '-' || pg_catalog.lpad(v_numero::text, 7, '0');

                    EXIT WHEN NOT EXISTS (
                        SELECT 1
                        FROM public.convenio_faturas AS f
                        WHERE f.codigo = v_codigo
                    );
                END LOOP;

                RETURN v_codigo;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.convenio_fatura_prepare_insert()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_ativo boolean;
            BEGIN
                SELECT c.ativo
                  INTO v_ativo
                  FROM public.convenios AS c
                 WHERE c.id = NEW.convenio_id
                 FOR SHARE;

                IF NOT FOUND OR NEW.convenio_id = 0 OR v_ativo IS DISTINCT FROM true THEN
                    RAISE EXCEPTION 'convênio inválido ou inativo para faturamento'
                        USING ERRCODE = '23514';
                END IF;

                NEW.codigo := public.convenio_fatura_next_codigo();
                NEW.subtotal := 0;
                NEW.total := 0;
                NEW.status := 'aberta';
                NEW.forma_pagamento := '';
                NEW.data_pagamento := NULL;
                NEW.assinatura_protocolo := NULL;
                NEW.cancelada_em := NULL;
                NEW.cancelada_por := NULL;
                NEW.motivo_cancelamento := NULL;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.convenio_fatura_normalize_totals()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_subtotal numeric(14, 2);
            BEGIN
                SELECT COALESCE(pg_catalog.sum(i.valor), 0)::numeric(14, 2)
                  INTO v_subtotal
                  FROM public.convenio_fatura_itens AS i
                 WHERE i.fatura_id = NEW.id;

                NEW.subtotal := v_subtotal;
                NEW.total := pg_catalog.greatest(v_subtotal - NEW.desconto, 0)::numeric(14, 2);

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.convenio_fatura_protect_update()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_tem_estorno boolean;
            BEGIN
                IF NEW.codigo IS DISTINCT FROM OLD.codigo THEN
                    RAISE EXCEPTION 'código da fatura é imutável'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.assinatura_protocolo IS NOT NULL
                   AND NEW.assinatura_protocolo IS DISTINCT FROM OLD.assinatura_protocolo THEN
                    RAISE EXCEPTION 'assinatura da fatura é imutável'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'cancelada' THEN
                    IF ROW(
                        NEW.convenio_id, NEW.periodo_inicio, NEW.periodo_fim,
                        NEW.subtotal, NEW.desconto, NEW.total, NEW.status,
                        NEW.forma_pagamento, NEW.data_pagamento, NEW.observacao,
                        NEW.assinatura_protocolo, NEW.cancelada_em, NEW.cancelada_por,
                        NEW.motivo_cancelamento
                    ) IS DISTINCT FROM ROW(
                        OLD.convenio_id, OLD.periodo_inicio, OLD.periodo_fim,
                        OLD.subtotal, OLD.desconto, OLD.total, OLD.status,
                        OLD.forma_pagamento, OLD.data_pagamento, OLD.observacao,
                        OLD.assinatura_protocolo, OLD.cancelada_em, OLD.cancelada_por,
                        OLD.motivo_cancelamento
                    ) THEN
                        RAISE EXCEPTION 'fatura terminal é imutável'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status = 'paga' THEN
                    IF NEW.status = 'cancelada' THEN
                        SELECT EXISTS (
                            SELECT 1
                            FROM public.financeiro_estornos AS e
                            WHERE e.origem_tipo = 'fatura'
                              AND e.origem_id = OLD.id
                        ) INTO v_tem_estorno;

                        IF NOT v_tem_estorno THEN
                            RAISE EXCEPTION 'fatura paga só pode ser cancelada após estorno'
                                USING ERRCODE = '23514';
                        END IF;

                        IF ROW(
                            NEW.convenio_id, NEW.periodo_inicio, NEW.periodo_fim,
                            NEW.subtotal, NEW.desconto, NEW.total,
                            NEW.forma_pagamento, NEW.data_pagamento, NEW.observacao,
                            NEW.assinatura_protocolo
                        ) IS DISTINCT FROM ROW(
                            OLD.convenio_id, OLD.periodo_inicio, OLD.periodo_fim,
                            OLD.subtotal, OLD.desconto, OLD.total,
                            OLD.forma_pagamento, OLD.data_pagamento, OLD.observacao,
                            OLD.assinatura_protocolo
                        ) THEN
                            RAISE EXCEPTION 'fatura terminal é imutável'
                                USING ERRCODE = '23514';
                        END IF;

                        RETURN NEW;
                    END IF;

                    IF ROW(
                        NEW.convenio_id, NEW.periodo_inicio, NEW.periodo_fim,
                        NEW.subtotal, NEW.desconto, NEW.total, NEW.status,
                        NEW.forma_pagamento, NEW.data_pagamento, NEW.observacao,
                        NEW.assinatura_protocolo, NEW.cancelada_em, NEW.cancelada_por,
                        NEW.motivo_cancelamento
                    ) IS DISTINCT FROM ROW(
                        OLD.convenio_id, OLD.periodo_inicio, OLD.periodo_fim,
                        OLD.subtotal, OLD.desconto, OLD.total, OLD.status,
                        OLD.forma_pagamento, OLD.data_pagamento, OLD.observacao,
                        OLD.assinatura_protocolo, OLD.cancelada_em, OLD.cancelada_por,
                        OLD.motivo_cancelamento
                    ) THEN
                        RAISE EXCEPTION 'fatura terminal é imutável'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status = 'aberta' AND NEW.status = 'cancelada' THEN
                    IF ROW(
                        NEW.convenio_id, NEW.periodo_inicio, NEW.periodo_fim,
                        NEW.subtotal, NEW.desconto, NEW.total,
                        NEW.forma_pagamento, NEW.data_pagamento, NEW.observacao,
                        NEW.assinatura_protocolo
                    ) IS DISTINCT FROM ROW(
                        OLD.convenio_id, OLD.periodo_inicio, OLD.periodo_fim,
                        OLD.subtotal, OLD.desconto, OLD.total,
                        OLD.forma_pagamento, OLD.data_pagamento, OLD.observacao,
                        OLD.assinatura_protocolo
                    ) THEN
                        RAISE EXCEPTION 'cancelamento não pode reescrever dados da fatura'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF OLD.status = 'aberta' AND NEW.status = 'paga' THEN
                    IF ROW(
                        NEW.convenio_id, NEW.periodo_inicio, NEW.periodo_fim,
                        NEW.subtotal, NEW.desconto, NEW.total,
                        NEW.assinatura_protocolo
                    ) IS DISTINCT FROM ROW(
                        OLD.convenio_id, OLD.periodo_inicio, OLD.periodo_fim,
                        OLD.subtotal, OLD.desconto, OLD.total,
                        OLD.assinatura_protocolo
                    ) THEN
                        RAISE EXCEPTION 'pagamento não pode reescrever dados financeiros da fatura'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.convenio_fatura_block_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                RAISE EXCEPTION 'fatura não pode ser excluída'
                    USING ERRCODE = '23514';
            END;
            $$;

            CREATE TRIGGER trg_convenio_fatura_10_prepare_insert
            BEFORE INSERT ON public.convenio_faturas
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_fatura_prepare_insert();

            CREATE TRIGGER trg_convenio_fatura_10_normalize_totals
            BEFORE UPDATE OF subtotal, desconto, total ON public.convenio_faturas
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_fatura_normalize_totals();

            CREATE TRIGGER trg_convenio_fatura_20_protect_update
            BEFORE UPDATE ON public.convenio_faturas
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_fatura_protect_update();

            CREATE TRIGGER trg_convenio_fatura_30_block_delete
            BEFORE DELETE ON public.convenio_faturas
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_fatura_block_delete();

            CREATE OR REPLACE FUNCTION public.convenio_fatura_item_prepare()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            DECLARE
                v_convenio_id integer;
                v_periodo_inicio date;
                v_periodo_fim date;
                v_fatura_status text;
                v_exame_status text;
                v_cobranca_destino text;
                v_convenio_cobranca_id integer;
                v_valor numeric(14, 2);
                v_atendimento_id bigint;
                v_data_atendimento date;
                v_ja_faturado boolean;
            BEGIN
                SELECT f.convenio_id, f.periodo_inicio, f.periodo_fim, f.status
                  INTO v_convenio_id, v_periodo_inicio, v_periodo_fim, v_fatura_status
                  FROM public.convenio_faturas AS f
                 WHERE f.id = NEW.fatura_id
                 FOR UPDATE;

                IF NOT FOUND OR v_fatura_status IS DISTINCT FROM 'aberta' THEN
                    RAISE EXCEPTION 'fatura não aceita novos itens'
                        USING ERRCODE = '23514';
                END IF;

                SELECT e.status, e.cobranca_destino, e.convenio_cobranca_id, e.valor, e.atendimento_id
                  INTO v_exame_status, v_cobranca_destino, v_convenio_cobranca_id, v_valor, v_atendimento_id
                  FROM public.atendimento_exames AS e
                 WHERE e.id = NEW.atendimento_exame_id
                 FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'exame não elegível para faturamento'
                        USING ERRCODE = '23514';
                END IF;

                SELECT a.data::date
                  INTO v_data_atendimento
                  FROM public.atendimentos AS a
                 WHERE a.id = v_atendimento_id;

                IF v_exame_status IS DISTINCT FROM 'finalizado'
                   OR v_cobranca_destino IS DISTINCT FROM 'convenio'
                   OR v_convenio_cobranca_id IS DISTINCT FROM v_convenio_id
                   OR v_data_atendimento IS NULL
                   OR v_data_atendimento < v_periodo_inicio
                   OR v_data_atendimento > v_periodo_fim THEN
                    RAISE EXCEPTION 'exame não elegível para faturamento'
                        USING ERRCODE = '23514';
                END IF;

                SELECT EXISTS (
                    SELECT 1
                    FROM public.convenio_fatura_itens AS i
                    JOIN public.convenio_faturas AS f ON f.id = i.fatura_id
                    WHERE i.atendimento_exame_id = NEW.atendimento_exame_id
                      AND f.status <> 'cancelada'
                ) INTO v_ja_faturado;

                IF v_ja_faturado THEN
                    RAISE EXCEPTION 'exame já pertence a fatura ativa'
                        USING ERRCODE = '23505';
                END IF;

                NEW.valor := v_valor;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.convenio_fatura_item_recalc()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                UPDATE public.convenio_faturas
                   SET subtotal = subtotal,
                       updated_at = pg_catalog.clock_timestamp()
                 WHERE id = NEW.fatura_id;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.convenio_fatura_item_protect()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'item de fatura não pode ser excluído'
                        USING ERRCODE = '23514';
                END IF;

                RAISE EXCEPTION 'item de fatura é imutável'
                    USING ERRCODE = '23514';
            END;
            $$;

            CREATE TRIGGER trg_convenio_fatura_item_10_prepare
            BEFORE INSERT ON public.convenio_fatura_itens
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_fatura_item_prepare();

            CREATE TRIGGER trg_convenio_fatura_item_20_recalc
            AFTER INSERT ON public.convenio_fatura_itens
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_fatura_item_recalc();

            CREATE TRIGGER trg_convenio_fatura_item_30_protect
            BEFORE UPDATE OR DELETE ON public.convenio_fatura_itens
            FOR EACH ROW
            EXECUTE FUNCTION public.convenio_fatura_item_protect();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_fatura_item_30_protect ON public.convenio_fatura_itens');
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_fatura_item_20_recalc ON public.convenio_fatura_itens');
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_fatura_item_10_prepare ON public.convenio_fatura_itens');
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_fatura_30_block_delete ON public.convenio_faturas');
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_fatura_20_protect_update ON public.convenio_faturas');
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_fatura_10_normalize_totals ON public.convenio_faturas');
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_fatura_10_prepare_insert ON public.convenio_faturas');
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_20_protect_particular ON public.convenios');
        DB::statement('DROP TRIGGER IF EXISTS trg_convenio_10_assign_codigo ON public.convenios');

        DB::statement('DROP FUNCTION IF EXISTS public.convenio_fatura_item_protect()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_fatura_item_recalc()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_fatura_item_prepare()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_fatura_block_delete()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_fatura_protect_update()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_fatura_normalize_totals()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_fatura_prepare_insert()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_fatura_next_codigo()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_protect_particular()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_assign_codigo()');
        DB::statement('DROP FUNCTION IF EXISTS public.convenio_next_codigo()');

        Schema::dropIfExists('convenio_fatura_itens');
        Schema::dropIfExists('convenio_faturas');
        Schema::dropIfExists('convenios');

        DB::table('protocolo_sequence')->whereIn('prefixo', ['CNV', 'FAT'])->delete();
    }
};
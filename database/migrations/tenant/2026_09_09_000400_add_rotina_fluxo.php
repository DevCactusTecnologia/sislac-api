<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_config', function (Blueprint $table): void {
            $table->smallInteger('singleton_key')->primary();
            $table->text('rotina_fluxo_modo')->default('completo');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE lab_config ADD CONSTRAINT lab_config_singleton_check CHECK (singleton_key = 1)');
        DB::statement("ALTER TABLE lab_config ADD CONSTRAINT lab_config_rotina_fluxo_modo_check CHECK (rotina_fluxo_modo IN ('completo', 'coleta_resultado', 'apenas_resultado'))");

        DB::table('lab_config')->insert([
            'singleton_key' => 1,
            'rotina_fluxo_modo' => 'completo',
        ]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rotina_fluxo_modo()
            RETURNS text
            LANGUAGE sql
            STABLE
            AS $$
                SELECT COALESCE(
                    (SELECT rotina_fluxo_modo FROM lab_config WHERE singleton_key = 1),
                    'completo'
                );
            $$;

            CREATE OR REPLACE FUNCTION rotina_prepare_atendimento_exame()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_modo text;
            BEGIN
                IF COALESCE(NEW.tipo_processo, 'INTERNO') = 'TERCEIRIZADO' THEN
                    RETURN NEW;
                END IF;

                v_modo := rotina_fluxo_modo();

                IF TG_OP = 'INSERT' THEN
                    IF v_modo = 'apenas_resultado' AND COALESCE(NEW.status, 'pendente') = 'pendente' THEN
                        NEW.status := 'analisado';
                        NEW.data_coleta := COALESCE(NEW.data_coleta, now());
                        NEW.data_analise := COALESCE(NEW.data_analise, NEW.data_coleta, now());

                        IF btrim(COALESCE(NEW.coletor, '')) = '' THEN
                            NEW.coletor := '__SEM_REGISTRO__';
                        END IF;

                        IF btrim(COALESCE(NEW.analista, '')) = '' THEN
                            NEW.analista := '__SEM_REGISTRO__';
                        END IF;
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.status IS NOT DISTINCT FROM OLD.status THEN
                    RETURN NEW;
                END IF;

                IF v_modo = 'coleta_resultado'
                    AND OLD.status = 'pendente'
                    AND NEW.status = 'coletado' THEN
                    NEW.status := 'analisado';
                    NEW.data_coleta := COALESCE(NEW.data_coleta, now());
                    NEW.data_analise := COALESCE(NEW.data_analise, NEW.data_coleta, now());

                    IF btrim(COALESCE(NEW.analista, '')) = '' THEN
                        NEW.analista := '__SEM_REGISTRO__';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION rotina_validate_atendimento_exame_status()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_modo text;
            BEGIN
                IF COALESCE(NEW.tipo_processo, 'INTERNO') = 'TERCEIRIZADO' THEN
                    RETURN NEW;
                END IF;

                IF NEW.status IS NOT DISTINCT FROM OLD.status THEN
                    RETURN NEW;
                END IF;

                IF NEW.status = 'cancelado' THEN
                    RETURN NEW;
                END IF;

                IF OLD.status = 'finalizado' THEN
                    RAISE EXCEPTION 'transição de status inválida: % -> %', OLD.status, NEW.status
                        USING ERRCODE = '23514';
                END IF;

                v_modo := rotina_fluxo_modo();

                IF OLD.status = 'cancelado' THEN
                    IF v_modo IN ('completo', 'coleta_resultado') AND NEW.status = 'pendente' THEN
                        RETURN NEW;
                    END IF;

                    IF v_modo = 'apenas_resultado' AND NEW.status = 'analisado' THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'transição de status inválida: % -> % no fluxo %', OLD.status, NEW.status, v_modo
                        USING ERRCODE = '23514';
                END IF;

                IF v_modo = 'apenas_resultado'
                    AND NEW.status IN ('pendente', 'coletado', 'em_bancada') THEN
                    RAISE EXCEPTION 'etapa % indisponível no fluxo %', NEW.status, v_modo
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.status = 'pendente' THEN
                    RETURN NEW;
                END IF;

                IF v_modo = 'completo' THEN
                    IF OLD.status = 'pendente' AND NEW.status <> 'coletado' THEN
                        RAISE EXCEPTION 'coleta obrigatória: % -> % não é permitido no fluxo %', OLD.status, NEW.status, v_modo
                            USING ERRCODE = '23514';
                    END IF;

                    IF OLD.status = 'coletado' AND NEW.status NOT IN ('em_bancada', 'analisado') THEN
                        RAISE EXCEPTION 'análise obrigatória: % -> % não é permitido no fluxo %', OLD.status, NEW.status, v_modo
                            USING ERRCODE = '23514';
                    END IF;

                    IF OLD.status = 'em_bancada' AND NEW.status <> 'analisado' THEN
                        RAISE EXCEPTION 'análise obrigatória: % -> % não é permitido no fluxo %', OLD.status, NEW.status, v_modo
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END IF;

                IF v_modo = 'coleta_resultado' THEN
                    IF NEW.status = 'em_bancada' THEN
                        RAISE EXCEPTION 'etapa % indisponível no fluxo %', NEW.status, v_modo
                            USING ERRCODE = '23514';
                    END IF;

                    IF OLD.status = 'pendente' AND NEW.status <> 'analisado' THEN
                        RAISE EXCEPTION 'coleta obrigatória: % -> % não é permitido no fluxo %', OLD.status, NEW.status, v_modo
                            USING ERRCODE = '23514';
                    END IF;

                    IF OLD.status = 'coletado' AND NEW.status <> 'analisado' THEN
                        RAISE EXCEPTION 'transição de status inválida: % -> % no fluxo %', OLD.status, NEW.status, v_modo
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_rotina_10_prepare_atendimento_exame
            BEFORE INSERT OR UPDATE ON atendimento_exames
            FOR EACH ROW EXECUTE FUNCTION rotina_prepare_atendimento_exame();

            CREATE TRIGGER trg_rotina_20_validate_atendimento_exame_status
            BEFORE UPDATE ON atendimento_exames
            FOR EACH ROW EXECUTE FUNCTION rotina_validate_atendimento_exame_status();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_rotina_20_validate_atendimento_exame_status ON atendimento_exames');
        DB::statement('DROP TRIGGER IF EXISTS trg_rotina_10_prepare_atendimento_exame ON atendimento_exames');
        DB::statement('DROP FUNCTION IF EXISTS rotina_validate_atendimento_exame_status()');
        DB::statement('DROP FUNCTION IF EXISTS rotina_prepare_atendimento_exame()');
        DB::statement('DROP FUNCTION IF EXISTS rotina_fluxo_modo()');
        Schema::dropIfExists('lab_config');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.guard_atendimento_exames_valor_original()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                IF OLD.valor_original IS NOT NULL
                   AND NEW.valor_original IS DISTINCT FROM OLD.valor_original THEN
                    NEW.valor_original := OLD.valor_original;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_guard_atendimento_exames_valor_original
            BEFORE UPDATE OF valor_original ON public.atendimento_exames
            FOR EACH ROW
            EXECUTE FUNCTION public.guard_atendimento_exames_valor_original();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_guard_atendimento_exames_valor_original ON public.atendimento_exames;
            DROP FUNCTION IF EXISTS public.guard_atendimento_exames_valor_original();
        SQL);
    }
};

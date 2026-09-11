<?php

namespace App\Domain\Atendimentos\Actions;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class UpdateRotinaConfig
{
    /** @return array{rotina_fluxo_modo: string} */
    public function handle(string $mode): array
    {
        return DB::transaction(function () use ($mode): array {
            $config = DB::table('lab_config')
                ->where('singleton_key', 1)
                ->lockForUpdate()
                ->first();

            if ($config === null) {
                throw new RuntimeException('Configuração da rotina não encontrada.');
            }

            $currentMode = is_string($config->rotina_fluxo_modo ?? null)
                ? $config->rotina_fluxo_modo
                : 'completo';

            if ($currentMode === $mode) {
                return ['rotina_fluxo_modo' => $mode];
            }

            DB::table('lab_config')
                ->where('singleton_key', 1)
                ->update([
                    'rotina_fluxo_modo' => $mode,
                    'updated_at' => now(),
                ]);

            if ($mode === 'coleta_resultado') {
                $this->normalizeToCollectionResult();
            } elseif ($mode === 'apenas_resultado') {
                $this->normalizeToResultOnly();
            }

            return ['rotina_fluxo_modo' => $mode];
        });
    }

    private function normalizeToCollectionResult(): void
    {
        DB::statement(<<<'SQL'
            UPDATE atendimento_exames
               SET status = 'analisado',
                   data_analise = COALESCE(data_analise, now()),
                   analista = CASE
                       WHEN btrim(COALESCE(analista, '')) = '' THEN '__SEM_REGISTRO__'
                       ELSE analista
                   END,
                   updated_at = now()
             WHERE tipo_processo = 'INTERNO'
               AND status IN ('coletado', 'em_bancada')
        SQL);
    }

    private function normalizeToResultOnly(): void
    {
        DB::statement(<<<'SQL'
            UPDATE atendimento_exames
               SET status = 'analisado',
                   data_coleta = COALESCE(data_coleta, now()),
                   data_analise = COALESCE(data_analise, data_coleta, now()),
                   coletor = CASE
                       WHEN btrim(COALESCE(coletor, '')) = '' THEN '__SEM_REGISTRO__'
                       ELSE coletor
                   END,
                   analista = CASE
                       WHEN btrim(COALESCE(analista, '')) = '' THEN '__SEM_REGISTRO__'
                       ELSE analista
                   END,
                   updated_at = now()
             WHERE tipo_processo = 'INTERNO'
               AND status IN ('pendente', 'coletado', 'em_bancada')
        SQL);
    }
}

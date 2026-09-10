<?php

namespace App\Console\Commands;

use App\Platform\Supabase\MigratedContractsLiveContract;
use App\Platform\Supabase\PacientesLiveContract;
use App\Platform\Supabase\SupabaseSource;
use Illuminate\Console\Command;
use Throwable;

final class CheckSupabaseLiveContract extends Command
{
    protected $signature = 'contract:supabase-live';

    protected $description = 'Compara contratos migrados com o Supabase real em modo somente leitura';

    public function handle(
        SupabaseSource $source,
        PacientesLiveContract $pacientes,
        MigratedContractsLiveContract $migrated,
    ): int {
        $config = config('database.connections.supabase_source');

        if (! is_array($config)
            || ! is_string($config['host'] ?? null)
            || $config['host'] === ''
            || ! is_string($config['username'] ?? null)
            || $config['username'] === '') {
            $this->error('Configure SUPABASE_DB_HOST e SUPABASE_DB_USERNAME para executar a conformidade live.');

            return 2;
        }

        try {
            $connection = $source->connection();
            $differences = array_merge(
                $migrated->check($connection),
                $pacientes->check($connection),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Não foi possível consultar o Supabase em modo live/read-only.');

            return 2;
        }

        $differences = array_values(array_unique($differences));

        if ($differences !== []) {
            $this->error('Contratos Supabase migrados: divergentes');

            foreach ($differences as $difference) {
                $this->line('- '.$difference);
            }

            return self::FAILURE;
        }

        $this->info('Contratos Supabase migrados: conformes');

        return self::SUCCESS;
    }
}

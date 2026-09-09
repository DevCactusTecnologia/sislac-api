<?php

namespace App\Console\Commands;

use App\Platform\Supabase\PacientesLiveContract;
use App\Platform\Supabase\SupabaseSource;
use Illuminate\Console\Command;
use Throwable;

final class CheckSupabaseLiveContract extends Command
{
    protected $signature = 'contract:supabase-live';

    protected $description = 'Compara contratos migrados com o Supabase real em modo somente leitura';

    public function handle(SupabaseSource $source, PacientesLiveContract $pacientes): int
    {
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
            $differences = $pacientes->check($source->connection());
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Não foi possível consultar o Supabase em modo live/read-only.');

            return 2;
        }

        if ($differences !== []) {
            $this->error('Pacientes: divergente');

            foreach ($differences as $difference) {
                $this->line('- '.$difference);
            }

            return self::FAILURE;
        }

        $this->info('Pacientes: conforme');

        return self::SUCCESS;
    }
}

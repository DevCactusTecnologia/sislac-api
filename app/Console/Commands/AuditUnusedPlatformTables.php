<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AuditUnusedPlatformTables extends Command
{
    protected $signature = 'platform:audit-unused-tables';

    protected $description = 'Audita tabelas centrais legadas sem removê-las';

    public function handle(): int
    {
        $found = false;
        $schema = Schema::connection('central');

        foreach (['plans', 'subscriptions'] as $table) {
            if (! $schema->hasTable($table)) {
                $this->info($table.': ausente');

                continue;
            }

            $found = true;
            $count = DB::connection('central')->table($table)->count();
            $this->warn(sprintf('%s: presente (%d registros)', $table, $count));
        }

        if ($found) {
            $this->warn('Nenhuma tabela foi removida. Revise o banco central antes de qualquer cleanup físico.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

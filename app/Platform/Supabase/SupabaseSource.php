<?php

namespace App\Platform\Supabase;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class SupabaseSource
{
    public function connection(): ConnectionInterface
    {
        $connection = DB::connection('supabase_source');
        $connection->statement('set default_transaction_read_only = on');

        return $connection;
    }
}

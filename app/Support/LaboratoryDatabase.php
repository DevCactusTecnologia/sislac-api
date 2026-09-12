<?php

namespace App\Support;

use App\Models\Laboratory;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LaboratoryDatabase
{
    public function connect(Laboratory $laboratory): Connection
    {
        $url = $laboratory->database_url;

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException('Laboratório sem conexão PostgreSQL configurada.');
        }

        config([
            'database.connections.lab' => [
                'driver' => 'pgsql',
                'url' => $url,
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
            ],
        ]);

        DB::purge('lab');

        $connection = DB::connection('lab');
        $connection->getPdo();

        return $connection;
    }

    public function disconnect(): void
    {
        DB::purge('lab');
        Config::forget('database.connections.lab');
    }
}

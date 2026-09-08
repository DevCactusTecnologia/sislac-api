<?php

use App\Platform\Supabase\SupabaseSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('configura a origem Supabase como PostgreSQL seguro e não default', function () {
    $source = config('database.connections.supabase_source');

    expect($source)
        ->toBeArray()
        ->and($source['driver'] ?? null)->toBe('pgsql')
        ->and($source['sslmode'] ?? null)->toBe('require')
        ->and($source['application_name'] ?? null)->toBe('sislac-api-supabase-source')
        ->and(config('database.default'))->not->toBe('supabase_source');
});

it('força a sessão da origem Supabase para somente leitura', function () {
    if (config('database.connections.central.driver') !== 'pgsql'
        || config('database.connections.central.database') === ':memory:') {
        $this->markTestSkipped('Este contrato de sessão exige PostgreSQL real.');
    }

    config()->set('database.connections.supabase_source', [
        ...config('database.connections.central'),
        'name' => 'supabase_source',
        'application_name' => 'sislac-api-supabase-source-test',
    ]);

    DB::purge('supabase_source');

    $connection = app(SupabaseSource::class)->connection();
    $state = $connection->selectOne('show default_transaction_read_only');

    expect($state->default_transaction_read_only ?? null)->toBe('on');

    $connection->beginTransaction();

    try {
        $connection->statement('create table __supabase_source_write_probe (id integer)');
        $writeWasBlocked = false;
    } catch (QueryException) {
        $writeWasBlocked = true;
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        DB::purge('supabase_source');
    }

    expect($writeWasBlocked)->toBeTrue();
});

<?php

it('falha explicitamente quando a origem Supabase live não está configurada', function () {
    config()->set('database.connections.supabase_source.host', null);
    config()->set('database.connections.supabase_source.username', null);

    $this->artisan('contract:supabase-live')
        ->expectsOutputToContain('SUPABASE_DB_HOST')
        ->assertExitCode(2);
});

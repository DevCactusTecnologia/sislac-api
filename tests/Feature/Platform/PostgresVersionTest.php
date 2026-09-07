<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('executa a suíte no mesmo major PostgreSQL da produção Supabase', function () {
    $row = DB::connection('central')->selectOne(
        "select current_setting('server_version_num') as version_num",
    );

    $major = intdiv((int) $row->version_num, 10000);

    expect($major)->toBe(17);
});

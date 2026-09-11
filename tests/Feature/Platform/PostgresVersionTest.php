<?php

use Illuminate\Support\Facades\DB;

it('executa a suíte no mesmo major PostgreSQL da produção Supabase', function () {
    $versionNum = DB::scalar("select current_setting('server_version_num')");
    $major = intdiv((int) $versionNum, 10000);

    expect($major)->toBe(17);
});

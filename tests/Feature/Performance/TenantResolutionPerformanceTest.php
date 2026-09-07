<?php

use App\Platform\Models\User;
use App\Platform\Queries\ActiveTenantMemberships;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('mantém uma query por resolução e registra p50 e p95 informativos', function () {
    $user = User::factory()->create();
    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Performance',
        'code' => 'lab-perf-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'database_name' => 'sislac_t_perf_'.Str::lower(Str::random(8)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('central')->table('memberships')->insert([
        'user_id' => $user->id,
        'tenant_id' => $tenantId,
        'role' => 'admin',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection = DB::connection('central');
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $durations = [];
    $query = new ActiveTenantMemberships;

    for ($iteration = 0; $iteration < 25; $iteration++) {
        $started = hrtime(true);
        expect($query->forUser((string) $user->id))->toBe([$tenantId]);
        $durations[] = (hrtime(true) - $started) / 1_000_000;
    }

    $executedQueries = $connection->getQueryLog();
    $connection->disableQueryLog();
    sort($durations);

    $p50 = $durations[12];
    $p95 = $durations[23];

    fwrite(STDERR, sprintf("Tenant resolution baseline: p50=%.3fms p95=%.3fms\n", $p50, $p95));

    expect($executedQueries)->toHaveCount(25)
        ->and($p50)->toBeGreaterThanOrEqual(0)
        ->and($p95)->toBeGreaterThanOrEqual($p50);
});

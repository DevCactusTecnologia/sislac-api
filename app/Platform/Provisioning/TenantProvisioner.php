<?php

namespace App\Platform\Provisioning;

use App\Platform\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stancl\Tenancy\Tenancy;
use Throwable;

final readonly class TenantProvisioner
{
    public function __construct(
        private PostgresDatabaseAdmin $databaseAdmin,
        private Tenancy $tenancy,
    ) {}

    public function provision(Tenant $tenant): void
    {
        $startedAt = now();
        $startedNs = hrtime(true);

        $runId = DB::connection('central')->table('provisioning_runs')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'status' => 'running',
            'started_at' => $startedAt,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);

        try {
            $this->databaseAdmin->withTenantLock(
                (string) $tenant->getKey(),
                fn () => $this->provisionLocked($tenant),
            );

            $finishedAt = now();
            DB::connection('central')->table('provisioning_runs')->whereKey($runId)->update([
                'status' => 'succeeded',
                'schema_version' => $this->schemaVersion(),
                'finished_at' => $finishedAt,
                'duration_ms' => $this->durationMs($startedNs),
                'updated_at' => $finishedAt,
            ]);

            DB::connection('central')->table('platform_audit')->insert([
                'action' => 'tenant.provisioned',
                'subject_type' => Tenant::class,
                'subject_id' => (string) $tenant->getKey(),
                'metadata' => json_encode(['database' => $tenant->database_name], JSON_THROW_ON_ERROR),
                'created_at' => $finishedAt,
            ]);
        } catch (Throwable $exception) {
            $finishedAt = now();

            DB::connection('central')->table('tenants')->whereKey($tenant->getKey())->update([
                'status' => 'provisioning_failed',
                'updated_at' => $finishedAt,
            ]);

            DB::connection('central')->table('provisioning_runs')->whereKey($runId)->update([
                'status' => 'failed',
                'finished_at' => $finishedAt,
                'duration_ms' => $this->durationMs($startedNs),
                'error_code' => class_basename($exception),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at' => $finishedAt,
            ]);

            DB::connection('central')->table('platform_audit')->insert([
                'action' => 'tenant.provisioning_failed',
                'subject_type' => Tenant::class,
                'subject_id' => (string) $tenant->getKey(),
                'metadata' => json_encode(['error' => class_basename($exception)], JSON_THROW_ON_ERROR),
                'created_at' => $finishedAt,
            ]);

            throw $exception;
        }
    }

    private function provisionLocked(Tenant $tenant): void
    {
        $tenant->refresh();
        $database = $tenant->database_name;

        if (! is_string($database) || $database === '') {
            throw new RuntimeException('Tenant sem database_name válido.');
        }

        $this->databaseAdmin->createDatabaseIfMissing($database);
        $this->tenancy->initialize($tenant);

        try {
            $exitCode = Artisan::call('migrate', [
                '--path' => database_path('migrations/tenant'),
                '--realpath' => true,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException('Migration do tenant falhou.');
            }

            $currentDatabase = DB::selectOne('select current_database() as database');

            if (($currentDatabase?->database ?? null) !== $database) {
                throw new RuntimeException('Smoke test conectou ao banco incorreto.');
            }
        } finally {
            $this->tenancy->end();
        }

        DB::connection('central')->table('tenants')->whereKey($tenant->getKey())->update([
            'status' => 'active',
            'updated_at' => now(),
        ]);
    }

    private function schemaVersion(): string
    {
        $files = glob(database_path('migrations/tenant/*.php')) ?: [];

        if ($files === []) {
            return 'foundation-empty';
        }

        sort($files);

        return basename((string) end($files), '.php');
    }

    private function durationMs(int $startedNs): int
    {
        return max(0, (int) round((hrtime(true) - $startedNs) / 1_000_000));
    }
}

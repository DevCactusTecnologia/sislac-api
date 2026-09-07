<?php

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Provisioning\TenantProvisioner;
use Illuminate\Console\Command;

final class ProvisionTenant extends Command
{
    protected $signature = 'tenants:provision {tenant : UUID ou código do laboratório}';

    protected $description = 'Cria e prepara o banco PostgreSQL de um laboratório';

    public function handle(TenantProvisioner $provisioner): int
    {
        $identifier = (string) $this->argument('tenant');

        $tenant = Tenant::query()
            ->whereKey($identifier)
            ->orWhere('code', $identifier)
            ->first();

        if ($tenant === null) {
            $this->error('Laboratório não encontrado.');

            return self::FAILURE;
        }

        $provisioner->provision($tenant);
        $this->info('Laboratório provisionado com sucesso.');

        return self::SUCCESS;
    }
}

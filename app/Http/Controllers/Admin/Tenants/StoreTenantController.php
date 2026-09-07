<?php

namespace App\Http\Controllers\Admin\Tenants;

use App\Http\Requests\Admin\StoreTenantRequest;
use App\Platform\Models\Tenant;
use App\Platform\Provisioning\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Throwable;

final readonly class StoreTenantController
{
    public function __construct(private TenantProvisioner $provisioner) {}

    public function __invoke(StoreTenantRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $id = (string) Str::uuid();

        $tenant = new Tenant;
        $tenant->setAttribute('id', $id);
        $tenant->setAttribute('name', $data['name']);
        $tenant->setAttribute('code', $data['code']);
        $tenant->setAttribute('status', 'provisioning');
        $tenant->setAttribute('database_name', 'sislac_t_'.str_replace('-', '', $id));
        $tenant->save();

        try {
            $this->provisioner->provision($tenant);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.tenants.index')
                ->withErrors(['provisioning' => 'Não foi possível provisionar o laboratório.']);
        }

        return redirect()
            ->route('admin.tenants.index')
            ->with('status', 'Laboratório provisionado com sucesso.');
    }
}

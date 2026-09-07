<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use App\Platform\Provisioning\PostgresDatabaseAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->adminProvisionedDatabases = [];

    $central = config('database.connections.central');

    config([
        'provisioning.database.host' => $central['host'],
        'provisioning.database.port' => (string) $central['port'],
        'provisioning.database.username' => $central['username'],
        'provisioning.database.password' => $central['password'],
        'provisioning.database.maintenance_database' => 'postgres',
        'provisioning.database.application_role' => $central['username'],
    ]);
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');

    foreach ($this->adminProvisionedDatabases as $database) {
        adminProvisioningControlConnection()->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
    }
});

function adminProvisioningControlConnection(): PDO
{
    $config = config('provisioning.database');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['maintenance_database']),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

it('protege listagem e formulário de laboratórios com Super Admin global', function () {
    $ordinary = User::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($ordinary, 'web')
        ->get('/admin/laboratorios')
        ->assertForbidden();

    $this->actingAs($superAdmin, 'web')
        ->get('/admin/laboratorios')
        ->assertOk()
        ->assertSee('Laboratórios');

    $this->get('/admin/laboratorios/novo')
        ->assertOk()
        ->assertSee('Novo laboratório');
});

it('cria registro central e provisiona um PostgreSQL físico com nome gerado no servidor', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $code = 'lab-'.Str::lower(Str::random(10));

    $this->actingAs($superAdmin, 'web')
        ->post('/admin/laboratorios', [
            'name' => 'Laboratório Novo',
            'code' => $code,
        ])
        ->assertRedirect('/admin/laboratorios');

    $tenant = Tenant::query()->where('code', $code)->firstOrFail();
    $this->adminProvisionedDatabases[] = $tenant->database_name;

    expect($tenant->name)->toBe('Laboratório Novo')
        ->and($tenant->status)->toBe('active')
        ->and($tenant->database_name)->toMatch('/\Asislac_t_[0-9a-f]{32}\z/')
        ->and(app(PostgresDatabaseAdmin::class)->databaseExists($tenant->database_name))->toBeTrue()
        ->and(DB::connection('central')->table('provisioning_runs')
            ->where('tenant_id', $tenant->getKey())
            ->where('status', 'succeeded')
            ->exists())->toBeTrue();
});

it('proíbe database_name vindo do navegador', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $code = 'lab-'.Str::lower(Str::random(10));

    $this->actingAs($superAdmin, 'web')
        ->from('/admin/laboratorios/novo')
        ->post('/admin/laboratorios', [
            'name' => 'Laboratório Malicioso',
            'code' => $code,
            'database_name' => 'sislac_t_escolhido_pelo_cliente',
        ])
        ->assertRedirect('/admin/laboratorios/novo')
        ->assertSessionHasErrors('database_name');

    expect(Tenant::query()->where('code', $code)->exists())->toBeFalse();
});

it('recusa code duplicado no banco central antes de provisionar', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $code = 'lab-'.Str::lower(Str::random(10));
    $id = (string) Str::uuid();

    DB::connection('central')->table('tenants')->insert([
        'id' => $id,
        'name' => 'Laboratório existente',
        'code' => $code,
        'status' => 'provisioning',
        'database_name' => 'sislac_t_'.str_replace('-', '', $id),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($superAdmin, 'web')
        ->from('/admin/laboratorios/novo')
        ->post('/admin/laboratorios', [
            'name' => 'Duplicado',
            'code' => $code,
        ])
        ->assertRedirect('/admin/laboratorios/novo')
        ->assertSessionHasErrors('code');

    expect(Tenant::query()->where('code', $code)->count())->toBe(1);
});

it('mantém tenant como provisioning_failed e retorna erro seguro quando o PostgreSQL não pode ser provisionado', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $code = 'lab-'.Str::lower(Str::random(10));

    config(['provisioning.database.application_role' => 'papel-invalido']);

    $this->actingAs($superAdmin, 'web')
        ->post('/admin/laboratorios', [
            'name' => 'Laboratório com falha',
            'code' => $code,
        ])
        ->assertRedirect('/admin/laboratorios')
        ->assertSessionHasErrors('provisioning');

    $tenant = Tenant::query()->where('code', $code)->firstOrFail();

    expect($tenant->status)->toBe('provisioning_failed')
        ->and($tenant->database_name)->toMatch('/\Asislac_t_[0-9a-f]{32}\z/')
        ->and(app(PostgresDatabaseAdmin::class)->databaseExists($tenant->database_name))->toBeFalse()
        ->and(DB::connection('central')->table('provisioning_runs')
            ->where('tenant_id', $tenant->getKey())
            ->where('status', 'failed')
            ->exists())->toBeTrue();
});

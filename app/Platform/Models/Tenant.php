<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\TenantRun;

final class Tenant extends Model implements TenantWithDatabase
{
    use CentralConnection;
    use HasDatabase;
    use HasUuids;
    use TenantRun;

    protected $table = 'tenants';

    protected $fillable = [
        'name',
        'code',
        'status',
        'database_name',
    ];

    public function getTenantKeyName(): string
    {
        return $this->getKeyName();
    }

    public function getTenantKey(): mixed
    {
        return $this->getKey();
    }

    public static function internalPrefix(): string
    {
        return 'tenancy_';
    }

    public function getInternal(string $key): mixed
    {
        return match ($key) {
            'db_name' => $this->getAttribute('database_name'),
            'db_connection' => 'tenant_template',
            'db_username', 'db_password' => null,
            default => null,
        };
    }

    public function setInternal(string $key, mixed $value): static
    {
        if ($key !== 'db_name') {
            throw new LogicException("Chave interna de tenancy não suportada: {$key}");
        }

        $this->setAttribute('database_name', $value);

        return $this;
    }
}

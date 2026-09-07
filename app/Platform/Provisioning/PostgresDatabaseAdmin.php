<?php

namespace App\Platform\Provisioning;

use Closure;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PostgresDatabaseAdmin
{
    public function databaseExists(string $database): bool
    {
        $this->assertDatabaseName($database);

        $statement = $this->connection()->prepare(
            'select exists(select 1 from pg_database where datname = ?) as exists',
        );
        $statement->execute([$database]);

        return filter_var($statement->fetchColumn(), FILTER_VALIDATE_BOOL);
    }

    public function createDatabaseIfMissing(string $database): void
    {
        $this->assertDatabaseName($database);

        if ($this->databaseExists($database)) {
            return;
        }

        $role = $this->applicationRole();
        $this->assertIdentifier($role, 'papel PostgreSQL');

        $this->connection()->exec(sprintf(
            'create database %s owner %s',
            $this->quoteIdentifier($database),
            $this->quoteIdentifier($role),
        ));
    }

    public function dropDatabaseIfExists(string $database): void
    {
        $this->assertDatabaseName($database);

        $this->connection()->exec(sprintf(
            'drop database if exists %s with (force)',
            $this->quoteIdentifier($database),
        ));
    }

    public function withTenantLock(string $tenantId, Closure $callback): mixed
    {
        $connection = $this->connection();
        $lock = $connection->prepare('select pg_advisory_lock(hashtextextended(?, 0))');
        $unlock = $connection->prepare('select pg_advisory_unlock(hashtextextended(?, 0))');
        $lock->execute([$tenantId]);

        try {
            return $callback();
        } finally {
            $unlock->execute([$tenantId]);
        }
    }

    private function connection(): PDO
    {
        $host = $this->configString('host');
        $port = $this->configString('port');
        $database = $this->configString('maintenance_database');
        $username = $this->configString('username');
        $password = (string) config('provisioning.database.password', '');

        return new PDO(
            "pgsql:host={$host};port={$port};dbname={$database}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function applicationRole(): string
    {
        return $this->configString('application_role');
    }

    private function configString(string $key): string
    {
        $value = config("provisioning.database.{$key}");

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Configuração de provisionamento ausente: {$key}.");
        }

        return $value;
    }

    private function assertDatabaseName(string $database): void
    {
        if (! preg_match('/\Asislac_t_[a-z0-9_]+\z/', $database)) {
            throw new InvalidArgumentException('Nome de banco de tenant inválido.');
        }
    }

    private function assertIdentifier(string $identifier, string $label): void
    {
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $identifier)) {
            throw new InvalidArgumentException("{$label} inválido.");
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

<?php

namespace App\Platform\Supabase;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class PacientesLiveContract
{
    /**
     * @return list<string>
     */
    public function check(ConnectionInterface $connection): array
    {
        $this->assertReadOnly($connection);

        /** @var array<string, mixed> $contract */
        $contract = json_decode(
            (string) file_get_contents(base_path('docs/contracts/pacientes.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        /** @var list<array<string, mixed>> $expectedColumns */
        $expectedColumns = array_map(
            static function (array $column): array {
                $expected = [
                    'name' => (string) $column['name'],
                    'type' => (string) $column['type'],
                    'nullable' => (bool) $column['nullable'],
                ];

                if (array_key_exists('default', $column)) {
                    $expected['default'] = $column['default'];
                }

                return $expected;
            },
            $contract['supabase']['columns'],
        );

        $actualColumns = array_values(array_map(
            static function (object $row): array {
                $values = get_object_vars($row);

                return [
                    'name' => (string) $values['name'],
                    'type' => (string) $values['type'],
                    'nullable' => (bool) $values['nullable'],
                    'default' => $values['default_value'] === null ? null : (string) $values['default_value'],
                ];
            },
            $connection->select(<<<'SQL'
                SELECT
                    column_name AS name,
                    CASE
                        WHEN data_type = 'timestamp with time zone' THEN 'timestamptz'
                        ELSE data_type
                    END AS type,
                    (is_nullable = 'YES') AS nullable,
                    column_default AS default_value
                FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = 'pacientes'
                ORDER BY ordinal_position
                SQL),
        ));

        $differences = $this->compareColumns($actualColumns, $expectedColumns);

        $rls = $connection->selectOne(<<<'SQL'
            SELECT c.relrowsecurity AS enabled
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relname = 'pacientes'
              AND c.relkind = 'r'
            SQL);
        $rlsValues = $rls === null ? [] : get_object_vars($rls);

        if (($rlsValues['enabled'] ?? false) !== true) {
            $differences[] = 'RLS desabilitado em public.pacientes';
        }

        $actualIndexes = array_map(
            static function (object $row): string {
                $values = get_object_vars($row);

                return (string) $values['indexname'];
            },
            $connection->select(<<<'SQL'
                SELECT indexname
                FROM pg_indexes
                WHERE schemaname = 'public'
                  AND tablename = 'pacientes'
                ORDER BY indexname
                SQL),
        );

        foreach ($contract['supabase']['indexes_observed'] as $index) {
            if (! in_array($index, $actualIndexes, true)) {
                $differences[] = 'índice ausente: '.$index;
            }
        }

        $actualPolicies = array_values(array_map(
            function (object $policy): array {
                $values = get_object_vars($policy);

                return [
                    'name' => (string) $values['name'],
                    'command' => strtoupper((string) $values['cmd']),
                    'permissive' => strtoupper((string) $values['permissive']),
                    'roles' => $this->parseRoles($values['roles'] ?? null),
                    'expression' => trim((string) $values['qual'].' '.(string) $values['with_check']),
                ];
            },
            $connection->select(<<<'SQL'
                SELECT
                    policyname AS name,
                    cmd,
                    permissive,
                    roles,
                    coalesce(qual, '') AS qual,
                    coalesce(with_check, '') AS with_check
                FROM pg_policies
                WHERE schemaname = 'public'
                  AND tablename = 'pacientes'
                ORDER BY cmd, policyname
                SQL),
        ));

        /** @var array<string, array{name:string,permission:string,role:string}> $expectedPolicies */
        $expectedPolicies = $contract['supabase']['policies_observed'];
        $differences = array_merge($differences, $this->comparePolicies($actualPolicies, $expectedPolicies));

        return array_values(array_unique($differences));
    }

    /**
     * @param  list<array<string, mixed>>  $actual
     * @param  list<array<string, mixed>>  $expected
     * @return list<string>
     */
    public function compareColumns(array $actual, array $expected): array
    {
        $differences = [];
        $actualByName = [];

        foreach ($actual as $column) {
            $actualByName[(string) $column['name']] = $column;
        }

        foreach ($expected as $column) {
            $name = (string) $column['name'];
            $observed = $actualByName[$name] ?? null;

            if ($observed === null) {
                $differences[] = "coluna ausente: {$name}";

                continue;
            }

            if ($observed['type'] !== $column['type']) {
                $differences[] = "tipo divergente em {$name}: {$observed['type']} != {$column['type']}";
            }

            if ($observed['nullable'] !== $column['nullable']) {
                $differences[] = "nullability divergente em {$name}";
            }

            if (array_key_exists('default', $column)) {
                $actualDefault = $this->normalizeDefault($observed['default'] ?? null);
                $expectedDefault = $this->normalizeDefault($column['default']);

                if ($actualDefault !== $expectedDefault) {
                    $differences[] = sprintf(
                        'default divergente em %s: %s != %s',
                        $name,
                        $this->displayDefault($actualDefault),
                        $this->displayDefault($expectedDefault),
                    );
                }
            }

            unset($actualByName[$name]);
        }

        foreach (array_keys($actualByName) as $name) {
            $differences[] = "coluna inesperada: {$name}";
        }

        return $differences;
    }

    /**
     * @param  list<array{name:string,command:string,permissive:string,roles:list<string>,expression:string}>  $actual
     * @param  array<string, array{name:string,permission:string,role:string}>  $expected
     * @return list<string>
     */
    public function comparePolicies(array $actual, array $expected): array
    {
        $differences = [];

        foreach ($expected as $command => $policyContract) {
            $matching = array_values(array_filter(
                $actual,
                static fn (array $policy): bool => $policy['command'] === $command,
            ));

            if (count($matching) !== 1) {
                $differences[] = sprintf(
                    'policy %s: quantidade divergente (%d != 1)',
                    $command,
                    count($matching),
                );

                continue;
            }

            $policy = $matching[0];

            if ($policy['name'] !== $policyContract['name']) {
                $differences[] = "policy {$command}: nome divergente";
            }

            if ($policy['permissive'] !== 'PERMISSIVE') {
                $differences[] = "policy {$command}: tipo divergente";
            }

            if ($policy['roles'] !== [$policyContract['role']]) {
                $differences[] = "policy {$command}: role divergente";
            }

            if (! str_contains($policy['expression'], $policyContract['permission'])) {
                $differences[] = "policy {$command}: permissão divergente";
            }
        }

        foreach ($actual as $policy) {
            if (! array_key_exists($policy['command'], $expected)) {
                $differences[] = sprintf(
                    'policy inesperada: %s (%s)',
                    $policy['name'],
                    $policy['command'],
                );
            }
        }

        return $differences;
    }

    private function normalizeDefault(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if (preg_match("/^'(.*)'::(?:text|character varying)$/s", $value, $matches) === 1) {
            return str_replace("''", "'", $matches[1]);
        }

        return $value;
    }

    private function displayDefault(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    private function parseRoles(mixed $roles): array
    {
        if (is_array($roles)) {
            return array_values(array_map('strval', $roles));
        }

        if (! is_string($roles)) {
            return [];
        }

        $roles = trim($roles, '{}');

        if ($roles === '') {
            return [];
        }

        return array_map(
            static fn (string $role): string => trim($role, " \t\n\r\0\x0B\""),
            explode(',', $roles),
        );
    }

    private function assertReadOnly(ConnectionInterface $connection): void
    {
        $state = $connection->selectOne('SHOW default_transaction_read_only');
        $values = $state === null ? [] : get_object_vars($state);

        if (($values['default_transaction_read_only'] ?? null) !== 'on') {
            throw new RuntimeException('supabase_source não está em modo read-only.');
        }
    }
}

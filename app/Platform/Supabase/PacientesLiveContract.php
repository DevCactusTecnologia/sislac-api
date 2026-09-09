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

        /** @var list<array{name:string,type:string,nullable:bool}> $expectedColumns */
        $expectedColumns = array_map(
            static fn (array $column): array => [
                'name' => (string) $column['name'],
                'type' => (string) $column['type'],
                'nullable' => (bool) $column['nullable'],
            ],
            $contract['supabase']['columns'],
        );

        $actualColumns = array_map(
            static function (object $row): array {
                $values = get_object_vars($row);

                return [
                    'name' => (string) $values['name'],
                    'type' => (string) $values['type'],
                    'nullable' => (bool) $values['nullable'],
                ];
            },
            $connection->select(<<<'SQL'
                SELECT
                    column_name AS name,
                    CASE
                        WHEN data_type = 'timestamp with time zone' THEN 'timestamptz'
                        ELSE data_type
                    END AS type,
                    (is_nullable = 'YES') AS nullable
                FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = 'pacientes'
                ORDER BY ordinal_position
                SQL),
        );

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

        $policies = $connection->select(<<<'SQL'
            SELECT cmd, coalesce(qual, '') AS qual, coalesce(with_check, '') AS with_check
            FROM pg_policies
            WHERE schemaname = 'public'
              AND tablename = 'pacientes'
            SQL);

        $policyExpressions = [];

        foreach ($policies as $policy) {
            $values = get_object_vars($policy);
            $command = strtoupper((string) $values['cmd']);
            $policyExpressions[$command] = ($policyExpressions[$command] ?? '')
                .' '.(string) $values['qual'].' '.(string) $values['with_check'];
        }

        $requiredPermissions = [
            'SELECT' => (string) $contract['supabase']['policies']['select'],
            'INSERT' => (string) $contract['supabase']['policies']['insert'],
            'UPDATE' => (string) $contract['supabase']['policies']['update'],
            'DELETE' => 'admin',
        ];

        foreach ($requiredPermissions as $command => $permission) {
            if (! str_contains($policyExpressions[$command] ?? '', $permission)) {
                $differences[] = "policy {$command} divergente: {$permission}";
            }
        }

        return array_values(array_unique($differences));
    }

    /**
     * @param list<array{name:string,type:string,nullable:bool}> $actual
     * @param list<array{name:string,type:string,nullable:bool}> $expected
     * @return list<string>
     */
    public function compareColumns(array $actual, array $expected): array
    {
        $differences = [];
        $actualByName = [];

        foreach ($actual as $column) {
            $actualByName[$column['name']] = $column;
        }

        foreach ($expected as $column) {
            $name = $column['name'];
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

            unset($actualByName[$name]);
        }

        foreach (array_keys($actualByName) as $name) {
            $differences[] = "coluna inesperada: {$name}";
        }

        return $differences;
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

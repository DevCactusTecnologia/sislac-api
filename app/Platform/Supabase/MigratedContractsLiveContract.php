<?php

namespace App\Platform\Supabase;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class MigratedContractsLiveContract
{
    public function __construct(
        private readonly SupabaseContractRegistry $registry,
    ) {}

    /**
     * @return list<string>
     */
    public function check(ConnectionInterface $connection): array
    {
        $this->assertReadOnly($connection);

        $relations = array_map(
            static fn (object $row): string => (string) get_object_vars($row)['identity'],
            $connection->select(<<<'SQL'
                SELECT format('%I.%I', n.nspname, c.relname) AS identity
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public'
                  AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                ORDER BY identity
                SQL),
        );

        $routines = array_map(
            static fn (object $row): string => (string) get_object_vars($row)['identity'],
            $connection->select(<<<'SQL'
                SELECT format(
                    '%I.%I(%s)',
                    n.nspname,
                    p.proname,
                    pg_get_function_identity_arguments(p.oid)
                ) AS identity
                FROM pg_proc p
                JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = 'public'
                  AND p.prokind IN ('f', 'p')
                ORDER BY identity
                SQL),
        );

        return $this->compare($relations, $routines);
    }

    /**
     * @param  list<string>  $relations
     * @param  list<string>  $routines
     * @return list<string>
     */
    public function compare(array $relations, array $routines): array
    {
        $availableRelations = array_fill_keys($relations, true);
        $availableRoutines = array_fill_keys($routines, true);
        $differences = [];

        foreach ($this->registry->contracts() as $contract) {
            foreach ($contract['relations'] as $relation) {
                if (! isset($availableRelations[$relation])) {
                    $differences[] = "{$contract['name']}: relação ausente: {$relation}";
                }
            }

            foreach ($contract['routines'] as $routine) {
                if (! isset($availableRoutines[$routine])) {
                    $differences[] = "{$contract['name']}: rotina ausente: {$routine}";
                }
            }
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

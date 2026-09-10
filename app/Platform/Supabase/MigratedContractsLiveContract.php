<?php

namespace App\Platform\Supabase;

final class MigratedContractsLiveContract
{
    public function __construct(
        private readonly SupabaseContractRegistry $registry,
    ) {}

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
}

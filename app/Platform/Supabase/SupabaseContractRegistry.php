<?php

namespace App\Platform\Supabase;

use RuntimeException;

final class SupabaseContractRegistry
{
    /**
     * @return list<array{name:string,relations:list<string>,routines:list<string>}>
     */
    public function contracts(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('docs/contracts/supabase-baseline.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($manifest) || ! is_array($manifest['migrated_contracts'] ?? null)) {
            throw new RuntimeException('Manifesto Supabase inválido.');
        }

        $contracts = [];

        foreach ($manifest['migrated_contracts'] as $contract) {
            if (! is_array($contract)
                || ! is_string($contract['name'] ?? null)
                || ! is_array($contract['relations'] ?? null)
                || ! is_array($contract['routines'] ?? null)) {
                throw new RuntimeException('Contrato Supabase migrado inválido.');
            }

            $contracts[] = [
                'name' => $contract['name'],
                'relations' => array_values(array_map('strval', $contract['relations'])),
                'routines' => array_values(array_map('strval', $contract['routines'])),
            ];
        }

        return $contracts;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn (array $contract): string => $contract['name'],
            $this->contracts(),
        );
    }
}

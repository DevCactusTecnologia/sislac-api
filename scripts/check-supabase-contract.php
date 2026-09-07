#!/usr/bin/env php
<?php

declare(strict_types=1);

$path = dirname(__DIR__).'/docs/contracts/supabase-baseline.json';
$data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($data)) {
    throw new RuntimeException('Manifesto Supabase inválido.');
}

$required = [
    ['frontend', 'sha'],
    ['supabase', 'postgres_major'],
    ['source_fingerprints', 'types_ts_blob'],
    ['source_fingerprints', 'runtime_contract_script_blob'],
    ['runtime_contract', 'tables_views_consumed'],
    ['runtime_contract', 'rpcs_consumed'],
    ['runtime_contract', 'edge_functions_consumed'],
    ['runtime_contract', 'buckets_consumed'],
    ['runtime_contract', 'realtime_tables'],
    ['storage_inventory'],
    ['edge_function_inventory'],
    ['sha256'],
];

foreach ($required as $segments) {
    $value = $data;

    foreach ($segments as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            throw new RuntimeException('Campo obrigatório ausente: '.implode('.', $segments));
        }

        $value = $value[$segment];
    }
}

$expectedHash = (string) $data['sha256'];
unset($data['sha256']);

$normalize = function (mixed $value) use (&$normalize): mixed {
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map($normalize, $value);
    }

    ksort($value);

    foreach ($value as $key => $item) {
        $value[$key] = $normalize($item);
    }

    return $value;
};

$canonical = json_encode(
    $normalize($data),
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);
$actualHash = hash('sha256', $canonical);

if (! hash_equals($expectedHash, $actualHash)) {
    throw new RuntimeException('Hash do manifesto Supabase divergente.');
}

if ($data['supabase']['postgres_major'] !== 17
    || $data['runtime_contract']['tables_views_consumed'] !== 77
    || $data['runtime_contract']['rpcs_consumed'] !== 52
    || $data['runtime_contract']['edge_functions_consumed'] !== 23
    || $data['runtime_contract']['buckets_consumed'] !== 4) {
    throw new RuntimeException('Contagens do contrato Supabase divergentes da baseline observada.');
}

if (count($data['storage_inventory']) !== 7 || count($data['edge_function_inventory']) !== 33) {
    throw new RuntimeException('Inventário Supabase incompleto.');
}

echo "OK — manifesto Supabase íntegro e determinístico.\n";

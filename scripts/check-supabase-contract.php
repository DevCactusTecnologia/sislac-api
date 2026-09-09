#!/usr/bin/env php
<?php

declare(strict_types=1);

$path = dirname(__DIR__).'/docs/contracts/supabase-baseline.json';
$data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($data)) {
    throw new RuntimeException('Manifesto Supabase inválido.');
}

$required = [
    ['version'],
    ['captured_at'],
    ['frontend', 'repository'],
    ['frontend', 'sha'],
    ['supabase', 'project_ref'],
    ['supabase', 'postgres_major'],
    ['supabase', 'runtime'],
    ['migrated_contracts'],
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

if ($data['version'] !== 2
    || $data['frontend']['repository'] !== 'DevCactusTecnologia/sislacprivado'
    || ! is_string($data['frontend']['sha'])
    || preg_match('/\A[0-9a-f]{40}\z/', $data['frontend']['sha']) !== 1
    || $data['supabase']['project_ref'] !== 'eramenhnqcbyctyiqwlm'
    || $data['supabase']['postgres_major'] !== 17
    || $data['supabase']['runtime'] !== 'single-tenant'
    || $data['migrated_contracts'] !== ['pacientes']) {
    throw new RuntimeException('Manifesto Supabase não representa a baseline aprovada da Fase 0.');
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

echo "OK — manifesto Supabase íntegro e determinístico.\n";

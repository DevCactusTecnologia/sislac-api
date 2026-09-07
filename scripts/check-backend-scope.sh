#!/usr/bin/env bash
set -euo pipefail

fail() {
  echo "::error::$1"
  exit 1
}

[ -d database/migrations/tenant ] || fail "database/migrations/tenant ausente: database-per-lab é obrigatório."

grep -q 'stancl/tenancy' composer.json || fail "stancl/tenancy ausente do contrato database-per-lab."
grep -q 'DatabaseTenancyBootstrapper::class' config/tenancy.php || fail "DatabaseTenancyBootstrapper ausente."

for forbidden in CacheTenancyBootstrapper FilesystemTenancyBootstrapper QueueTenancyBootstrapper; do
  if grep -q "$forbidden" config/tenancy.php; then
    fail "$forbidden não possui consumidor aprovado."
  fi
done

[ ! -e docs/superpowers/specs/2026-09-07-supabase-integration-only-remediation-design.md ] || \
  fail "Spec Supabase-only superseded reapareceu."

if grep -Eq '(^|[[:space:]])(redis|horizon|reverb):' docker-compose.yml; then
  fail "Redis/Horizon/Reverb não possuem consumidor runtime nesta fundação."
fi

if grep -Eq 'REDIS_|REVERB_|HORIZON_' .env.example; then
  fail "Variáveis de infraestrutura futura sem consumidor encontradas no .env.example."
fi

if grep -q 'pecl install redis' docker/php/Dockerfile; then
  fail "Extensão Redis instalada sem consumidor runtime."
fi

echo "OK — escopo Laravel database-per-lab permanece enxuto e coerente."

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

fail() {
  echo "::error::$1"
  exit 1
}

if ! command -v rg >/dev/null 2>&1; then
  fail "ripgrep (rg) é necessário para validar o escopo do backend."
fi

for removed in \
  docker-compose.yml \
  docker \
  config/tenancy.php \
  config/provisioning.php \
  config/sanctum.php \
  app/Platform/Provisioning \
  app/Platform/Tenancy \
  resources/views/admin \
  database/migrations/central \
  database/migrations/tenant; do
  [ ! -e "$removed" ] || fail "Infraestrutura removida reapareceu: $removed"
done

if rg -n \
  --glob '!docs/superpowers/**' \
  --glob '!scripts/check-backend-scope.sh' \
  -e 'stancl/tenancy' \
  -e 'TenantProvisioner' \
  -e 'PostgresDatabaseAdmin' \
  -e 'sislac_central' \
  -e 'DB_ROOT_' \
  -e 'TENANT_DB_' \
  -e 'X-Tenant' \
  -e "DB::connection\\(['\"]central['\"]\\)" \
  -e "DB::connection\\(['\"]tenant['\"]\\)" \
  -e 'tenant_template' \
  -e 'supabase_source' \
  -e 'SupabaseSource' \
  -e 'SupabaseContractRegistry' \
  -e 'MigratedContractsLiveContract' \
  -e 'PacientesLiveContract' \
  -e 'check-supabase-contract' \
  app bootstrap config routes database scripts tests README.md docs/ARCHITECTURE.md docs/DEPLOY.md docs/SEGURANCA.md .env.example composer.json .github/workflows/ci.yml 2>/dev/null; then
  fail "Resíduo funcional da arquitetura central/database-per-lab/Supabase paralelo encontrado."
fi

if rg -n 'REDIS_|REVERB_|HORIZON_' .env.example; then
  fail "Variáveis de infraestrutura sem consumidor encontradas no .env.example."
fi

if rg -n "'redis'\s*=>\s*\[" config/cache.php config/queue.php 2>/dev/null; then
  fail "Redis configurado sem consumidor runtime."
fi

grep -q '^QUEUE_CONNECTION=sync$' .env.example || \
  fail "QUEUE_CONNECTION deve permanecer sync enquanto não houver consumidor runtime."

if rg -n 'Inspiring|Artisan::command\(.inspire' routes/console.php; then
  fail "Comando de exemplo do Laravel reapareceu."
fi

for scaffold in tests/Unit/ExampleTest.php public/favicon.ico; do
  [ ! -e "$scaffold" ] || fail "Arquivo de scaffold sem função reapareceu: $scaffold"
done

for frontend_orphan in package.json .npmrc vite.config.js resources/css/app.css .claude/skills/tailwindcss-development; do
  [ ! -e "$frontend_orphan" ] || fail "Pipeline frontend sem consumidor reapareceu: $frontend_orphan"
done

if rg -n 'npm install|npm run build' composer.json; then
  fail "Composer voltou a depender de pipeline frontend inexistente."
fi

echo "OK — backend Laravel permanece enxuto sobre um único Supabase."

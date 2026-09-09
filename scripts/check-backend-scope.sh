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
  fail "Infraestrutura sem consumidor runtime reapareceu no compose."
fi

if grep -Eq 'REDIS_|REVERB_|HORIZON_' .env.example; then
  fail "Variáveis de infraestrutura sem consumidor encontradas no .env.example."
fi

if [ -e database/migrations/0001_01_01_000002_create_jobs_table.php ]; then
  fail "Migration de fila persistente reapareceu sem consumidor runtime."
fi

grep -q '^QUEUE_CONNECTION=sync$' .env.example || \
  fail "QUEUE_CONNECTION deve permanecer sync enquanto não houver consumidor runtime."

for orphan in \
  TENANT_DB_NAME_PREFIX \
  WHATSAPP_META_ \
  PDF_SHARE_SECRET \
  INTERNAL_WEBHOOK_SECRET \
  AWS_ACCESS_KEY_ID \
  AWS_SECRET_ACCESS_KEY \
  AWS_BUCKET \
  AWS_ENDPOINT \
  AWS_USE_PATH_STYLE_ENDPOINT; do
  if grep -q "$orphan" .env.example; then
    fail "$orphan não possui consumidor aprovado na fundação."
  fi
done

if grep -q "'s3' => \[" config/filesystems.php; then
  fail "Disco S3 configurado sem consumidor runtime."
fi

if grep -q "'ses' => \[" config/services.php; then
  fail "Configuração SES sem consumidor runtime."
fi

if grep -Eq 'Redis|Horizon|Reverb|PDF_SHARE_SECRET|INTERNAL_WEBHOOK_SECRET' docs/DEPLOY.md; then
  fail "Documentação de deploy contém infraestrutura ou segredos fora da fundação atual."
fi

if grep -q 'pecl install redis' docker/php/Dockerfile; then
  fail "Extensão de infraestrutura instalada sem consumidor runtime."
fi

if grep -Eq 'Inspiring|Artisan::command\(.inspire' routes/console.php; then
  fail "Comando de exemplo do Laravel reapareceu."
fi

for scaffold in tests/Unit/ExampleTest.php database/migrations/tenant/.gitkeep public/favicon.ico; do
  [ ! -e "$scaffold" ] || fail "Arquivo de scaffold sem função reapareceu: $scaffold"
done

for frontend_orphan in package.json .npmrc vite.config.js resources/css/app.css .claude/skills/tailwindcss-development; do
  [ ! -e "$frontend_orphan" ] || fail "Pipeline frontend sem consumidor reapareceu: $frontend_orphan"
done

if grep -Eq 'npm install|npm run build' composer.json; then
  fail "Composer voltou a depender de pipeline frontend inexistente."
fi

if grep -R -nE "DB::connection\(['\"]supabase_source['\"]\)" app --include='*.php' \
  | grep -v '^app/Platform/Supabase/SupabaseSource.php:'; then
  fail "supabase_source só pode ser aberto por App\\Platform\\Supabase\\SupabaseSource."
fi

echo "OK — escopo Laravel database-per-lab permanece enxuto e coerente."

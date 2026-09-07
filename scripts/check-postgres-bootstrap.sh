#!/usr/bin/env bash
set -euo pipefail

root_password='sislac_root_bootstrap_ci'
app_password='sislac_app_bootstrap_ci'
tmp_env="$(mktemp)"
created_dotenv=false

cleanup() {
  docker compose --env-file "$tmp_env" down -v --remove-orphans >/dev/null 2>&1 || true
  [ "$created_dotenv" = false ] || rm -f .env
  rm -f "$tmp_env"
}
trap cleanup EXIT

sed \
  -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=${root_password}|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${app_password}|" \
  -e "s|^TENANT_DB_PASSWORD=.*|TENANT_DB_PASSWORD=${app_password}|" \
  .env.example > "$tmp_env"

if [ ! -e .env ]; then
  cp "$tmp_env" .env
  created_dotenv=true
fi

docker compose --env-file "$tmp_env" up -d postgres

healthy=false
for _ in $(seq 1 30); do
  if [ "$(docker inspect --format '{{.State.Health.Status}}' sislac-postgres 2>/dev/null || true)" = 'healthy' ]; then
    healthy=true
    break
  fi
  sleep 1
done

[ "$healthy" = true ] || {
  docker compose --env-file "$tmp_env" logs postgres
  echo '::error::PostgreSQL do compose não ficou saudável.'
  exit 1
}

identity="$(
  docker compose --env-file "$tmp_env" exec -T \
    -e PGPASSWORD="$app_password" \
    postgres psql \
      -h 127.0.0.1 \
      -U sislac_app \
      -d sislac_central \
      -v ON_ERROR_STOP=1 \
      -Atqc "SELECT current_user || '|' || current_database();"
)"

[ "$identity" = 'sislac_app|sislac_central' ] || {
  echo "::error::Identidade inesperada no bootstrap PostgreSQL: $identity"
  exit 1
}

privileges="$(
  docker compose --env-file "$tmp_env" exec -T \
    -e PGPASSWORD="$app_password" \
    postgres psql \
      -h 127.0.0.1 \
      -U sislac_app \
      -d sislac_central \
      -v ON_ERROR_STOP=1 \
      -AtF '|' \
      -c "SELECT rolcreatedb, rolcreaterole, rolsuper FROM pg_roles WHERE rolname = current_user;"
)"

[ "$privileges" = 'f|f|f' ] || {
  echo "::error::sislac_app recebeu privilégios administrativos inesperados: $privileges"
  exit 1
}

echo 'OK — bootstrap PostgreSQL do compose cria o banco central com autenticação e privilégios mínimos.'

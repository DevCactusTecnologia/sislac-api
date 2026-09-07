#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v rg >/dev/null 2>&1; then
    echo "::error::ripgrep (rg) é necessário para validar o contrato de banco."
    exit 2
fi

violations=0

# O backend SISLAC é PostgreSQL-only. O skeleton não deve manter drivers de
# aplicação que possam ser usados acidentalmente em produção.
if rg -n "'mysql'\s*=>|'mariadb'\s*=>|Pdo\\\\Mysql" config/database.php; then
    echo "::error::config/database.php ainda expõe MySQL/MariaDB."
    violations=$((violations + 1))
fi

# Código de aplicação não pode selecionar drivers incompatíveis diretamente.
if rg -n --type php \
    -e "DB::connection\(['\"](?:mysql|mariadb)['\"]\)" \
    -e "['\"]driver['\"]\s*=>\s*['\"](?:mysql|mariadb)['\"]" \
    app 2>/dev/null; then
    echo "::error::Código da aplicação referencia MySQL/MariaDB."
    violations=$((violations + 1))
fi

if [ "$violations" -gt 0 ]; then
    exit 1
fi

echo "OK — contrato PostgreSQL-only preservado."

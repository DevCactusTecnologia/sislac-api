#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v rg >/dev/null 2>&1; then
    echo "::error::ripgrep (rg) é necessário para validar o contrato de banco."
    exit 2
fi

violations=0

# Produção é PostgreSQL-only. SQLite permanece permitido exclusivamente como
# conexão de teste local; nenhum outro driver deve existir na configuração.
if rg -n "'(mysql|mariadb|sqlsrv)'\s*=>|Pdo\\\\Mysql" config/database.php; then
    echo "::error::config/database.php expõe driver incompatível com PostgreSQL."
    violations=$((violations + 1))
fi

if rg -n --type php \
    -e "DB::connection\(['\"](?:mysql|mariadb|sqlsrv)['\"]\)" \
    -e "['\"]driver['\"]\s*=>\s*['\"](?:mysql|mariadb|sqlsrv)['\"]" \
    app 2>/dev/null; then
    echo "::error::Código da aplicação referencia driver incompatível."
    violations=$((violations + 1))
fi

if rg -n 'database/database\.sqlite|touch\(.?database/database\.sqlite' composer.json; then
    echo "::error::Composer contém scaffold que cria SQLite fora do fluxo de testes."
    violations=$((violations + 1))
fi

if [ "$violations" -gt 0 ]; then
    exit 1
fi

echo "OK — contrato PostgreSQL-only preservado."

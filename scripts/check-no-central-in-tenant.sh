#!/usr/bin/env bash
# Guard: código de tenant (app/Domain/**, app/Http/Controllers/Tenant/**, etc.)
# não pode importar a conexão central nem os models de plataforma (App\Platform\**).
# Só o middleware de tenancy e comandos administrativos podem tocar Platform.
#
# Rodado no CI. Falha o build se qualquer violação for detectada.
#
# Regra explícita neste ADR-001: Platform e Domain vivem em pastas separadas
# com fronteira estrita entre elas.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v rg >/dev/null 2>&1; then
    echo "::error::ripgrep (rg) é necessário. Instale com: apt-get install ripgrep"
    exit 2
fi

violations=0

# Padrões proibidos dentro do domínio (fora de app/Platform e do middleware)
if rg -n --type php \
    -e '\\Platform\\' \
    -e "DB::connection\(['\"]central['\"]\)" \
    app/Domain app/Http/Controllers/Tenant 2>/dev/null; then
    echo "::error::Código de tenant importou algo do plano central acima."
    violations=$((violations + 1))
fi

# Padrões proibidos dentro do Platform (fora do middleware de tenancy)
if rg -n --type php \
    -e "DB::connection\(['\"]tenant['\"]\)" \
    -e '\\Domain\\' \
    app/Platform 2>/dev/null; then
    echo "::error::Código central referenciou a conexão tenant ou o Domain acima."
    violations=$((violations + 1))
fi

if [ "$violations" -gt 0 ]; then
    exit 1
fi

echo "OK — nenhuma violação de fronteira Platform ↔ Domain."

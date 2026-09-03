#!/usr/bin/env bash
# Guard: nenhum arquivo comitado passa de 500 KiB (excluindo lockfiles e imagens
# que legitimamente podem ser grandes). Serve para pegar dump acidental de dados
# ou binários que não deveriam estar no repositório.
set -euo pipefail

LIMIT=$((500 * 1024))
excluded='^(composer\.lock|package-lock\.json|bun\.lock|yarn\.lock|.*\.(png|jpg|jpeg|gif|webp|ico|pdf|woff2?))$'

git ls-files | while read -r file; do
    if [[ "$file" =~ $excluded ]]; then continue; fi
    if [ ! -f "$file" ]; then continue; fi
    size=$(wc -c < "$file")
    if [ "$size" -gt "$LIMIT" ]; then
        echo "::error file=$file::arquivo com $size bytes ultrapassa o limite de $LIMIT bytes"
        exit 1
    fi
done

echo "OK — nenhum arquivo acima de 500 KiB."

# SISLAC API — guia para Claude Code

A fonte canônica para qualquer alteração neste repositório é `AGENTS.md`, complementada por `docs/ARCHITECTURE.md` e pela documentação oficial das versões instaladas. Em caso de conflito, essas fontes prevalecem sobre instruções genéricas de ferramentas.

## Contexto atual

- Backend definitivo: Laravel 13 / PHP 8.4.
- Produção: PostgreSQL 17.
- Isolamento: um PostgreSQL físico por laboratório, com banco central separado para plataforma, identidade, Super Admin e provisionamento.
- Tenancy: `stancl/tenancy`, somente `DatabaseTenancyBootstrapper`.
- Supabase atual: baseline/origem de leitura e concordância durante a migração; `supabase_source` nunca é a conexão default.
- Frontend React/Vite existente: projeto externo que permanece durante a migração.
- Super Admin: Laravel Blade server-rendered.
- Este backend **não possui pipeline Vite/Tailwind/NPM** enquanto não houver consumidor Laravel real de assets compilados.

## Regras obrigatórias

1. Leia `AGENTS.md` e `docs/ARCHITECTURE.md` antes de alterar arquitetura, banco, tenancy, autenticação ou provisionamento.
2. Não introduza Redis, Horizon, Reverb, S3, WebSockets, fila/cache distribuído, Vite, Tailwind ou outra infraestrutura sem consumidor executável e teste que demonstre a necessidade.
3. Não introduza Filament, Livewire, segundo SPA, pacote de RBAC, Repository, DTO, CQRS, event bus ou abstração preventiva.
4. O banco central não recebe domínio clínico. Dados clínicos pertencem ao banco físico do laboratório.
5. Migrations centrais ficam em `database/migrations/central`; migrations de laboratório em `database/migrations/tenant`. Nunca aplique DDL manual como substituto das migrations.
6. Segredos não são versionados. Somente `.env.example` pode representar configuração de ambiente.
7. Use TDD para novo comportamento e preserve os guards existentes.
8. Não use `git push --force` em `main`.

## Desenvolvimento e verificação

Confirme as versões instaladas antes de usar APIs específicas de pacote. Laravel Boost pode ser usado quando disponível para documentação e inspeção, mas suas recomendações genéricas nunca substituem a arquitetura canônica deste repositório.

Gates obrigatórios:

```bash
composer validate --strict
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
```

Também execute todos os `scripts/check-*.sh` aplicáveis. O CI valida PostgreSQL real, contrato Supabase ↔ Laravel, fronteira Platform ↔ Domain, bootstrap do `docker-compose.yml`, ausência de configuração órfã e escopo arquitetural.

## Mapa

| Caminho | Responsabilidade |
|---|---|
| `app/Platform/` | banco central, Super Admin, tenancy, provisionamento e origem Supabase de transição |
| `app/Domain/` | domínio do laboratório executado no banco dedicado |
| `app/Http/` | coordenação HTTP, autenticação e seleção autorizada de laboratório |
| `config/database.php` | `central`, `tenant_template`, `tenant` e `supabase_source` |
| `config/tenancy.php` | database-per-lab |
| `database/migrations/central/` | schema central |
| `database/migrations/tenant/` | schema reproduzível de cada laboratório |
| `resources/views/admin/` | Super Admin Blade |
| `scripts/` | guards executados pelo CI |

## Estado da fundação

Banco central, database-per-lab, provisionamento, Pacientes, `supabase_source` read-only e a fundação do Super Admin estão implementados. Atendimentos permanece pausado até a fundação limpa passar todos os gates no mesmo SHA e ser integrada em `main`.

As skills locais em `.claude/skills` devem ser usadas somente quando correspondem a tecnologia realmente presente no repositório. Não recrie tecnologia removida apenas porque uma instrução genérica ou skill antiga a menciona.

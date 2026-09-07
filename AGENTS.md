# SISLAC API — guia para agentes (Claude Code, Cursor, Codex)

Leia antes de qualquer alteração. A referência normativa da arquitetura é o
ADR‑001 (link em `docs/ARCHITECTURE.md`).

## O que é este repositório

Backend em **Laravel 13 / PHP 8.4** do SISLAC, sistema de gestão de
laboratórios de análises clínicas. **Multi-tenant, um banco PostgreSQL por
laboratório**, um único deploy, todos os clientes em `sislac.com.br` (sem
subdomínio). O front atual (Lovable + Supabase) continua em produção até a
Fase 4; este backend é desenvolvido em paralelo.

## Regras que não se negociam

1. **PostgreSQL sempre.** Nunca MySQL/MariaDB, nunca SQLite fora dos testes
   locais. Conexões: `central` (padrão) e `tenant` (molde, preenchida em
   runtime). Nunca chame `DB::connection('tenant')` fora do middleware de
   tenancy.
2. **Fronteira Platform ↔ Domain.** `app/Platform` não importa `App\Domain\*`;
   `app/Domain` não importa `App\Platform\*`. O CI falha se cruzar
   (`scripts/check-no-central-in-tenant.sh`).
3. **O tenant vem do vínculo, não do cabeçalho.** `X-Tenant` só escolhe entre
   os laboratórios aos quais o usuário autenticado já pertence; a API confere
   o vínculo em `memberships` antes de aceitar.
4. **Segredos nunca no repositório.** Só `.env.example` é comitado. Chaves de
   API (Meta, gateway, S3) vêm de variáveis de ambiente do host.
5. **Dados de paciente são dados de saúde (LGPD art. 11).** Em dev e testes,
   só dados sintéticos ou anonimizados. Nunca copie dump de produção para o PC.
6. **Auditoria é append-only.** Trilhas clínicas e financeiras nunca são
   apagadas ou reescritas por código de aplicação (RDC 978/2025 ANVISA).
7. **WhatsApp só pela Cloud API oficial da Meta.** Nunca Baileys ou libs não
   oficiais.
8. **Sem `git push --force` em `main`.** Sem commit que quebre os gates do CI.

## Como trabalhar

- **Documentação oficial é normativa.** Confirme APIs e comportamento na versão
  instalada antes de implementar; o código legado serve como contrato de
  comportamento, não como justificativa para contrariar segurança ou o framework.
- **Código legível por humanos.** Nomes devem expressar o negócio; cada classe tem
  uma responsabilidade clara; não crie helper, service, repository, DTO ou trait
  sem uma fronteira concreta que justifique sua existência.
- **YAGNI e DRY com critério.** Não antecipe extensibilidade e não abstraia uma
  única chamada apenas para reduzir linhas. Extraia quando houver regra de negócio,
  reutilização real ou isolamento que melhore o teste e a leitura.
- **TDD para comportamento.** Novo comportamento nasce de um teste que falha pelo
  motivo esperado, recebe a implementação mínima correta e volta a ficar verde.
- Formatação: `vendor/bin/pint` (preset `laravel`, sem regras extras).
- Testes: `vendor/bin/pest`. Nomes dos testes em português e orientados ao
  comportamento (`it('recalcula o total quando um exame é cancelado')`).
- Análise estática: `vendor/bin/phpstan analyse` com **Larastan nível 8**; é gate
  obrigatório, não aviso opcional.
- Dependências: `composer audit --locked` deve permanecer verde.
- Migrations: `database/migrations/central/` para o banco central e
  `database/migrations/tenant/` para os bancos de laboratório. Nunca DDL manual
  como fonte de verdade.
- Regras de negócio hoje em triggers/RPCs do Supabase são preservadas por testes
  de concordância e movidas apenas quando a implementação Laravel equivalente
  estiver comprovada.
- Commits pequenos, mensagens no formato `tipo: resumo` (`feat:`, `fix:`,
  `chore:`, `docs:`, `test:`), em português.

## Laravel Boost

Laravel Boost está instalado e versionado. Não repita `composer require` nem
`boost:install` a cada sessão. Agentes suportados devem usar as diretrizes e
skills geradas pelo Boost; `CLAUDE.md` contém as diretrizes específicas do
Claude Code. `boost.json` é a configuração versionada da instalação.

## Mapa

| Caminho | O que é |
|---------|---------|
| `app/Platform/` | plano central e identidade |
| `app/Domain/` | regras do laboratório |
| `app/Http/Controllers/HealthController.php` | `GET /api/health` |
| `config/database.php` | conexões `central` e `tenant` |
| `docker/`, `docker-compose.yml` | PHP-FPM, Nginx, Postgres, Redis, pgAdmin |
| `docs/` | arquitetura, deploy, segurança e especificações |
| `scripts/` | guards do CI |
| `.github/workflows/ci.yml` | Pint · Larastan · Pest · audit · guards |

## Fase atual

**Fase 1 em execução.** Banco central e seleção segura de tenant já possuem
fundação e testes. Dependências oficiais da fase (`laravel/sanctum`,
`stancl/tenancy`, `larastan/larastan` e `laravel/boost`) estão instaladas com
`composer.lock` real. O próximo contrato é autenticação Sanctum stateful,
contexto multi-database e provisionamento reproduzível antes dos endpoints de
domínio.

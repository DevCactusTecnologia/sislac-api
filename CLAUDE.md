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
8. **Sem `git push --force` em `main`.** Sem commit que quebre `pint --test`
   ou `pest`.

## Como trabalhar

- Formatação: `vendor/bin/pint` (preset `laravel`, sem regras extras).
- Testes: `vendor/bin/pest`. Novo comportamento → novo teste, em Pest, em
  português nos nomes (`it('recalcula o total quando um exame é cancelado')`).
- Análise estática: `vendor/bin/phpstan analyse` (Larastan, nível 8) quando
  instalado.
- Migrations: `database/migrations/central/` para o banco central e
  `database/migrations/tenant/` para os bancos de laboratório (a partir da
  Fase 1). Nunca DDL à mão no banco.
- Regras de negócio que hoje estão em triggers do Supabase (recálculo de totais
  e status, auditoria por diff) são reescritas como serviços com testes e
  validadas por **concordância** contra o comportamento atual antes do corte.
- Commits pequenos, mensagens no formato `tipo: resumo` (`feat:`, `fix:`,
  `chore:`, `docs:`, `test:`), em português.

## Mapa

| Caminho | O que é |
|---------|---------|
| `app/Platform/` | plano central (Fase 1) |
| `app/Domain/` | regras do laboratório (Fase 3) |
| `app/Http/Controllers/HealthController.php` | `GET /api/health` |
| `config/database.php` | conexões `central` e `tenant` |
| `docker/`, `docker-compose.yml` | PHP-FPM, Nginx, Postgres, Redis, pgAdmin |
| `docs/` | arquitetura, deploy, segurança, DBeaver, pgAdmin |
| `scripts/` | guards do CI |
| `.github/workflows/ci.yml` | Pint · Larastan · Pest · guards |

## Fase atual

**Fase 0 concluída.** Próxima: Fase 1 — banco central (`tenants`, `users`,
`memberships`, `plans`, `subscriptions`, `provisioning_runs`,
`platform_audit`), `stancl/tenancy` multi-database, Sanctum, super-admin em
Filament e o pipeline de provisionamento (`CREATE DATABASE` → migrations →
seed → smoke test → registro).

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>

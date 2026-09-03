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
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# SISLAC — API

Backend do SISLAC (sistema de gestão para laboratórios de análises clínicas) em
**Laravel 13 / PHP 8.4** sobre **PostgreSQL**, com Redis, filas, WebSocket e
geração de laudos em PDF. Multi-tenant no modelo **um banco por laboratório**,
todos os clientes no mesmo endereço (`sislac.com.br`), um único deploy — ver
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) e o ADR‑001.

Este repositório é a **Fase 0**: fundação (esqueleto, Docker, CI, docs, health
check). O código de domínio entra nas fases seguintes.

## Rodar no PC (Windows + Laravel Herd)

Pré-requisitos: [Laravel Herd](https://herd.laravel.com) (PHP 8.4 + Composer) e
um PostgreSQL 16 local — o mais simples é o do Docker Desktop, via compose:

```powershell
composer install
copy .env.example .env
php artisan key:generate

# Banco + Redis + pgAdmin locais (Docker Desktop). A senha do papel sislac_app
# vem de DB_PASSWORD no .env — troque antes de subir pela primeira vez.
docker compose up -d postgres redis pgadmin

php artisan migrate
```

Sem Docker: instale o PostgreSQL 16 para Windows, crie o papel `sislac_app` e o
banco `sislac_central` (o SQL está em
[docker/postgres/init/01-create-central.sql](docker/postgres/init/01-create-central.sql))
e aponte o `.env` para ele.

Abra `http://sislac-api.test/api/health` (Herd usa o nome da pasta do projeto)
ou rode `php artisan serve` e use `http://127.0.0.1:8000/api/health`. Resposta
esperada:

```json
{ "app": "SISLAC", "env": "local", "time": "...", "database": { "connection": "central", "status": "ok" } }
```

- pgAdmin local: <http://localhost:5050> (`PGADMIN_EMAIL` / `PGADMIN_PASSWORD` do `.env`).
- DBeaver / TablePlus: [docs/CONECTAR_DBEAVER.md](docs/CONECTAR_DBEAVER.md).

## Qualidade

```powershell
vendor\bin\pint --test        # formatação (preset laravel)
vendor\bin\pest               # testes
vendor\bin\phpstan analyse    # análise estática (depois de instalar o Larastan)
```

O CI (GitHub Actions) roda Pint, Larastan e Pest contra um PostgreSQL 16 real,
mais os guards de repositório (`scripts/`): fronteira Platform ↔ Domain, nenhum
arquivo acima de 500 KiB, nenhum `.env` comitado. Nada entra em `main` sem
isso verde.

Para ligar o Larastan (a etapa fica como aviso até então):

```powershell
composer require --dev larastan/larastan
```

Recomendado também: [Laravel Boost](https://github.com/laravel/boost), que dá
ao Claude Code / Cursor acesso à documentação e ao estado real da aplicação:

```powershell
composer require --dev laravel/boost
php artisan boost:install
```

## Produção (VPS Hostinger, Ubuntu 24.04)

Tudo em Docker Compose, portas só em `127.0.0.1`, Nginx público com TLS na
frente. Passo a passo em [docs/DEPLOY.md](docs/DEPLOY.md); segurança em
[docs/SEGURANCA.md](docs/SEGURANCA.md); pgAdmin via túnel SSH em
[docs/PGADMIN.md](docs/PGADMIN.md).

## Estrutura

```
app/
├─ Platform/        # banco central: tenants, usuários, vínculos, planos (Fase 1)
├─ Domain/          # regras do laboratório: pacientes, atendimentos, exames (Fase 3)
└─ Http/Controllers # HealthController hoje; Platform/ e Tenant/ nas próximas fases
config/database.php # conexões `central` (padrão) e `tenant` (molde, preenchida em runtime)
docker/             # PHP-FPM 8.4, Nginx, init do Postgres
docs/               # arquitetura, deploy, segurança, ferramentas de banco
scripts/            # guards executados no CI
```

## Fases

| Fase | Escopo | Estado |
|------|--------|--------|
| 0 | Laravel + Docker + CI + docs + health check | **concluída** |
| 1 | Banco central, tenancy multi-database (`stancl/tenancy`), Sanctum, super-admin (Filament), provisionamento | próxima |
| 2 | PDF (Chromium), WhatsApp Cloud API, integrações de apoio, Horizon/Reverb | pendente |
| 3 | Endpoints de domínio; front Lovable passa a consumir esta API | pendente |
| 4 | Migração dos dados do Supabase e corte | pendente |
| 5 | SaaS comercial (self-service, planos, cobrança) | pendente |

## Licença

Privado. Uso interno da Devcactus Tecnologia.

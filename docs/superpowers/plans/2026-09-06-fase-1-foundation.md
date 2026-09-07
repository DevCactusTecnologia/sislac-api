# Fase 1 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir a fundação Laravel da Fase 1 com autenticação SPA stateful, banco central, tenancy database-per-lab, resolução segura de tenant, provisionamento e gates de conformidade/segurança/performance.

**Architecture:** O banco `central` guarda identidade e plataforma; cada laboratório possui um banco PostgreSQL próprio. O SPA first-party usa Laravel Sanctum com sessão/cookie/CSRF. O contexto tenant só é inicializado depois de autenticação e validação de membership, mantendo a fronteira `Platform ↔ Domain` já protegida pelo CI.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, Redis 7, Pest 4, Laravel Pint, Larastan/PHPStan 8, Laravel Sanctum, stancl/tenancy 3.x.

**Spec:** `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`

## Global Constraints

- Documentação oficial Laravel 13, Supabase e PostgreSQL é normativa.
- PostgreSQL é o único banco de produção suportado.
- Um banco PostgreSQL por laboratório; nenhuma autorização depende apenas de `X-Tenant`.
- `app/Platform` não importa `App\Domain`; `app/Domain` não importa `App\Platform`.
- SPA first-party usa Sanctum stateful por cookie/CSRF; bearer token é reservado a integrações que realmente precisem de token.
- Código pequeno, nomes explícitos, uma responsabilidade por unidade e nenhuma abstração sem consumidor real.
- TDD obrigatório para comportamento novo: RED → GREEN → REFACTOR.
- Dados de testes são exclusivamente sintéticos.
- Nenhum segredo, dump ou dado clínico real no repositório.
- Não copiar débitos de segurança/performance do Supabase apenas para obter paridade superficial.

---

## Estrutura de arquivos planejada

- `app/Platform/Models/*`: modelos centrais (`User`, `Tenant`, `Membership`, `Plan`, `Subscription`, `ProvisioningRun`, `PlatformAudit`).
- `app/Platform/Tenancy/TenantResolver.php`: decisão pura de tenant autorizado.
- `app/Http/Middleware/EnsureTenantContext.php`: única ponte HTTP entre identidade central e inicialização tenant.
- `app/Http/Controllers/Auth/*`: login/logout/session do SPA, sem regra de domínio.
- `app/Http/Requests/Auth/LoginRequest.php`: validação e rate limit de login.
- `app/Platform/Provisioning/*`: orquestração e adapters de criação/migração/smoke test.
- `database/migrations/central/*`: schema da plataforma.
- `database/migrations/tenant/*`: schema por laboratório, inicialmente mínimo para smoke/isolation.
- `routes/central.php`, `routes/tenant.php`: rotas separadas por contexto.
- `tests/Feature/Auth/*`, `tests/Feature/Tenancy/*`, `tests/Feature/Provisioning/*`: contratos HTTP/integração.
- `tests/Unit/Platform/*`: regras puras de resolução e invariantes.
- `scripts/check-database-contract.sh`: guard que proíbe drivers/uso incompatíveis com PostgreSQL no código do SISLAC.
- `scripts/check-supabase-contract.php`: manifesto determinístico da superfície que será migrada nas ondas seguintes.

### Task 1: Tooling e baseline reproduzível

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `.github/workflows/ci.yml`
- Modify: `docker-compose.yml`
- Modify: `README.md`
- Create: `scripts/check-database-contract.sh`

**Produces:** ambiente Laravel 13 com Boost, Larastan, Sanctum e stancl/tenancy instalados; PostgreSQL 17 em dev/CI; guard PostgreSQL-only.

- [ ] **Step 1: RED — escrever guard PostgreSQL-only**

Criar `scripts/check-database-contract.sh` que falha quando `config/database.php` contém conexões SISLAC MySQL/MariaDB ou quando `app/Platform`, `app/Domain` e `app/Http` usam `mysql`, `mariadb` ou `DB::connection('mysql')`.

- [ ] **Step 2: verificar RED**

Run: `bash scripts/check-database-contract.sh`
Expected: FAIL enquanto o skeleton ainda expuser drivers incompatíveis com a regra do projeto.

- [ ] **Step 3: instalar dependências pela documentação oficial**

Run:
```bash
composer require laravel/sanctum stancl/tenancy
composer require --dev laravel/boost larastan/larastan
php artisan boost:install
```

Usar `php artisan install:api` somente se sua saída for compatível com a estrutura já existente; revisar o diff e não aceitar scaffolding desnecessário.

- [ ] **Step 4: alinhar PostgreSQL**

Atualizar CI e Docker Compose para PostgreSQL 17, preservando Redis 7. Remover conexões MySQL/MariaDB do `config/database.php` somente depois do guard existir.

- [ ] **Step 5: verificar GREEN**

Run:
```bash
bash scripts/check-database-contract.sh
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
composer audit
```
Expected: todos PASS, sem etapa Larastan opcional.

- [ ] **Step 6: commit**

`chore: alinha tooling e PostgreSQL da fase 1`

### Task 2: Schema central mínimo e modelos explícitos

**Files:**
- Move/replace: migration padrão de usuários para `database/migrations/central/*`
- Create: migrations centrais para `tenants`, `memberships`, `plans`, `subscriptions`, `provisioning_runs`, `platform_audit`, `personal_access_tokens`
- Create: `app/Platform/Models/User.php`
- Create: `app/Platform/Models/Tenant.php`
- Create: `app/Platform/Models/Membership.php`
- Create: `app/Platform/Models/Plan.php`
- Create: `app/Platform/Models/Subscription.php`
- Create: `app/Platform/Models/ProvisioningRun.php`
- Create: `app/Platform/Models/PlatformAudit.php`
- Modify: `config/auth.php`
- Test: `tests/Feature/Platform/CentralSchemaTest.php`

**Produces:** schema central reproduzível, FKs/uniques explícitas e identidade central usada pelo guard `web`/Sanctum.

- [ ] **Step 1: RED — contrato do schema central**

Teste deve provar: `users`, `tenants`, `memberships`, `plans`, `subscriptions`, `provisioning_runs`, `platform_audit` existem; `memberships` não aceita duplicação `(user_id, tenant_id)`; FK inválida falha; `platform_audit` não possui fluxo de delete da aplicação.

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Platform/CentralSchemaTest.php`
Expected: FAIL por tabelas/modelos ainda inexistentes.

- [ ] **Step 3: implementar migrations/modelos mínimos**

Usar tipos explícitos, `foreignId()->constrained()`, índices apenas para lookup real e casts tipados. Nenhum `BaseModel` genérico.

- [ ] **Step 4: verificar GREEN e regressão**

Run: `vendor/bin/pest tests/Feature/Platform/CentralSchemaTest.php && vendor/bin/pest --parallel`
Expected: PASS.

- [ ] **Step 5: commit**

`feat: cria plano central da plataforma`

### Task 3: Sanctum stateful e sessão segura

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `config/session.php`
- Create/publish: `config/sanctum.php` se necessário pela instalação oficial
- Modify: `.env.example`
- Create: `app/Http/Requests/Auth/LoginRequest.php`
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Create: `app/Http/Controllers/Auth/LogoutController.php`
- Create: `app/Http/Controllers/Auth/SessionController.php`
- Modify: `routes/web.php` / `routes/api.php` conforme contrato Sanctum oficial
- Test: `tests/Feature/Auth/SpaAuthenticationTest.php`

**Produces:** login/logout/session first-party com CSRF, regeneração/invalidação de sessão e `auth:sanctum`.

- [ ] **Step 1: RED — autenticação SPA**

Testar: credencial válida autentica; login regenera session id; credencial inválida retorna 422 sem revelar se o e-mail existe; logout invalida sessão e regenera token CSRF; rota protegida sem sessão retorna 401; usuário autenticado recebe apenas campos públicos.

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Auth/SpaAuthenticationTest.php`
Expected: FAIL por endpoints ausentes.

- [ ] **Step 3: implementar conforme Laravel 13**

Em `bootstrap/app.php`, habilitar `$middleware->statefulApi();`. Login usa `Auth::attempt(...)` e `$request->session()->regenerate()`. Logout usa `Auth::guard('web')->logout()`, `invalidate()` e `regenerateToken()`.

- [ ] **Step 4: rate limit de login**

Chave de limitação combina e-mail normalizado + IP; resposta não revela existência da conta.

- [ ] **Step 5: GREEN**

Run: `vendor/bin/pest tests/Feature/Auth/SpaAuthenticationTest.php && vendor/bin/pest --parallel`
Expected: PASS.

- [ ] **Step 6: commit**

`feat: adiciona autenticação SPA com Sanctum`

### Task 4: Resolver de tenant autorizado

**Files:**
- Create: `app/Platform/Tenancy/TenantResolution.php`
- Create: `app/Platform/Tenancy/TenantResolver.php`
- Test: `tests/Unit/Platform/Tenancy/TenantResolverTest.php`

**Consumes:** usuário central e memberships ativas.

**Produces:** `TenantResolver::resolve(User $user, ?string $requestedTenantId): TenantResolution`.

- [ ] **Step 1: RED — matriz de resolução**

Testar separadamente: zero memberships → denied; uma ativa sem header → seleciona automaticamente; uma ativa + header diferente → denied; múltiplas sem header → selection_required; múltiplas + header autorizado → seleciona; membership suspensa/inativa nunca conta.

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Unit/Platform/Tenancy/TenantResolverTest.php`
Expected: FAIL por classes inexistentes.

- [ ] **Step 3: implementar regra pura**

`TenantResolver` não toca HTTP, banco tenant, cache ou globals. Recebe apenas dados centrais necessários e devolve resultado explícito.

- [ ] **Step 4: GREEN**

Run: `vendor/bin/pest tests/Unit/Platform/Tenancy/TenantResolverTest.php`
Expected: PASS.

- [ ] **Step 5: commit**

`feat: resolve tenant exclusivamente por membership`

### Task 5: Middleware de contexto tenant e isolamento

**Files:**
- Create: `app/Http/Middleware/EnsureTenantContext.php`
- Modify: `bootstrap/app.php`
- Create: `routes/tenant.php`
- Create: `database/migrations/tenant/0001_create_tenant_health_table.php`
- Test: `tests/Feature/Tenancy/TenantIsolationTest.php`

**Consumes:** `TenantResolver`.

**Produces:** middleware que autentica/autoriza antes de inicializar tenancy e sempre encerra o contexto em `finally`.

- [ ] **Step 1: RED — isolamento A/B**

Criar dois bancos tenant de teste com marcadores distintos. Provar: A lê A; A + `X-Tenant=B` recebe 403; multi-membership pode escolher B; exceção numa requisição não contamina a seguinte; nenhuma query tenant ocorre antes da membership ser validada.

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantIsolationTest.php`
Expected: FAIL por middleware/contexto ausentes.

- [ ] **Step 3: configurar stancl/tenancy**

Usar multi-database PostgreSQL, central connection `central`, database bootstrapper e customização de identificação própria; não usar identificação por domínio/subdomínio.

- [ ] **Step 4: implementar middleware mínimo**

Fluxo: `auth:sanctum` → resolver membership → inicializar tenant → `next($request)` → terminar tenancy em `finally`.

- [ ] **Step 5: GREEN + guard arquitetural**

Run:
```bash
vendor/bin/pest tests/Feature/Tenancy/TenantIsolationTest.php
bash scripts/check-no-central-in-tenant.sh
vendor/bin/pest --parallel
```
Expected: PASS.

- [ ] **Step 6: commit**

`feat: isola requisições por banco de laboratório`

### Task 6: Provisionamento idempotente e auditável

**Files:**
- Create: `app/Platform/Provisioning/TenantProvisioner.php`
- Create: `app/Platform/Provisioning/TenantDatabaseManager.php`
- Create: `app/Platform/Provisioning/TenantMigrationRunner.php`
- Create: `app/Platform/Provisioning/TenantSmokeCheck.php`
- Create: `app/Platform/Provisioning/ProvisioningResult.php`
- Test: `tests/Feature/Provisioning/TenantProvisionerTest.php`

**Produces:** pipeline `provisioning → create db → migrate → seed mínimo → smoke → active`, com falha terminal registrada e tenant nunca ativo parcialmente.

- [ ] **Step 1: RED — estados e idempotência**

Testar: sucesso ativa somente no fim; falha de migration não ativa; retry não cria banco duplicado; nome do DB é derivado/validado e não aceita input arbitrário; credencial root não aparece em log/resposta.

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Provisioning/TenantProvisionerTest.php`
Expected: FAIL.

- [ ] **Step 3: implementar adapters pequenos**

Separar criação de DB, migrations e smoke check para que cada efeito tenha uma interface explícita. Nenhuma requisição HTTP usa papel `DB_ROOT_*`.

- [ ] **Step 4: GREEN**

Run: `vendor/bin/pest tests/Feature/Provisioning/TenantProvisionerTest.php && vendor/bin/pest --parallel`
Expected: PASS.

- [ ] **Step 5: commit**

`feat: provisiona tenant de forma idempotente`

### Task 7: Segurança, concorrência e performance da fundação

**Files:**
- Create: `tests/Feature/Security/TenantSecurityTest.php`
- Create: `tests/Feature/Security/AuthenticationSecurityTest.php`
- Create: `tests/Feature/Performance/TenantResolutionPerformanceTest.php`
- Create: `tests/Feature/Concurrency/TenantContextConcurrencyTest.php`
- Modify: `.github/workflows/ci.yml`
- Modify: `docs/SEGURANCA.md`

**Produces:** gates automatizados para BOLA/IDOR, tenant forgery, session fixation, query count e vazamento de contexto.

- [ ] **Step 1: escrever testes de segurança que expressem invariantes**

Casos mínimos: tenant forjado, membership suspensa, IDs válidos de outro tenant, mass assignment de tenant/database, sessão antiga após login/logout, payload inválido, erro sem stack trace/segredo.

- [ ] **Step 2: escrever benchmark controlado**

Registrar p50/p95 e query-count da resolução de tenant com fixtures fixas. Inicialmente o teste gera baseline informativa; só vira gate numérico após duas medições estáveis no mesmo runner CI.

- [ ] **Step 3: concorrência**

Executar requisições alternadas A/B e provar que contexto, cache prefix e conexão não vazam.

- [ ] **Step 4: CI completo**

Run:
```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
composer audit
bash scripts/check-no-central-in-tenant.sh
bash scripts/check-database-contract.sh
```
Expected: PASS, zero etapas opcionais.

- [ ] **Step 5: atualizar documentação de segurança**

Remover afirmação antiga de “Sanctum com bearer curto + refresh” para o SPA e documentar cookie/CSRF stateful conforme Laravel 13.

- [ ] **Step 6: commit**

`test: fecha gates de segurança e isolamento da fase 1`

### Task 8: Manifesto de concordância Supabase → Laravel

**Files:**
- Create: `docs/contracts/supabase-baseline.json`
- Create: `scripts/check-supabase-contract.php`
- Create: `tests/Contract/SupabaseBaselineTest.php`
- Modify: `docs/ARCHITECTURE.md`

**Produces:** snapshot versionado da superfície relevante do Supabase e do SHA do `sislacprivado`, sem dados de produção.

- [ ] **Step 1: RED — teste do manifesto**

Exigir campos: frontend SHA, Postgres major, tabelas/views/enums consumidos, RPCs consumidas, Edge Functions, buckets, canais realtime e hash do manifesto.

- [ ] **Step 2: gerar somente metadados**

O script não lê linhas clínicas. Ele trabalha com catálogo/schema e inventários de código, mantendo zero dados de pacientes.

- [ ] **Step 3: validar drift**

Mudança na superfície consumida pelo frontend deve produzir diff legível e falhar até o contrato ser revisado.

- [ ] **Step 4: GREEN e suíte completa**

Run: `vendor/bin/pest tests/Contract/SupabaseBaselineTest.php && vendor/bin/pest --parallel`
Expected: PASS.

- [ ] **Step 5: commit**

`test: versiona contrato de concordância com Supabase`

## Definition of Done desta fundação

- PostgreSQL 17 em dev e CI.
- Sanctum stateful/CSRF para SPA first-party conforme Laravel 13.
- Banco central reproduzível por migrations.
- Membership é a única autoridade para escolher tenant.
- `X-Tenant` nunca concede acesso sozinho.
- Contexto tenant encerrado deterministicamente mesmo em exceção.
- Provisionamento idempotente, auditado e sem root no runtime HTTP.
- Pint, Larastan nível 8, Pest, Composer Audit e guards verdes.
- Testes explícitos de segurança, isolamento, concorrência e performance presentes.
- Manifesto Supabase/frontend versionado sem dados sensíveis.
- Nenhum módulo clínico é migrado antes desta fundação estar verde.
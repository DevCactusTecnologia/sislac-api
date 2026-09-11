# Simplificação do SISLAC API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduzir o `sislac-api` a uma API Laravel enxuta que usa o Supabase existente como única fonte de PostgreSQL/Auth/Storage, removendo completamente banco central, database-per-lab, provisioning, Super Admin Laravel e resíduos sem consumidor.

**Architecture:** O frontend envia `Authorization: Bearer <Supabase access token>`. Laravel valida a identidade no Supabase Auth, abre contexto PostgreSQL transacional com role `authenticated` e claims do usuário, aplica autorização via `public.has_permission`, executa as regras de domínio e encerra o contexto automaticamente ao fim da transação. Não existe segundo banco Laravel, `X-Tenant`, membership central ou schema clínico duplicado.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17/Supabase, Supabase Auth/RLS, Pest 4, Larastan 3.11, Pint.

**Spec:** `docs/superpowers/specs/2026-09-10-supabase-backend-simplification-design.md`

## Global Constraints

- TDD obrigatório: RED → GREEN → REFACTOR em cada mudança comportamental.
- Não executar migration, DDL ou escrita no Supabase de produção durante esta refatoração.
- Não usar `postgres`, `supabase_admin`, `service_role` nem role com `BYPASSRLS` como credencial HTTP do backend.
- Não executar `composer update` amplo. Remoções de pacotes devem ser explícitas e o lockfile só muda como consequência dessas remoções.
- `sislacprivado/supabase/migrations` continua sendo a fonte de verdade do schema/RLS enquanto o Supabase for o banco do produto.
- `QUEUE_CONNECTION=sync`; sem Redis, Horizon, Reverb ou worker sem consumidor real.
- Documentos sob `docs/superpowers/` podem permanecer como histórico; documentação operacional não pode afirmar database-per-lab após esta mudança.
- Antes de apagar uma classe/arquivo, confirmar que não há consumidor executável fora da arquitetura removida.

---

### Task 1: Fixar a nova arquitetura com guard RED

**Files:**
- Modify: `tests/Feature/Architecture/BackendScopeTest.php`
- Modify: `tests/Feature/Architecture/UnusedQueueInfrastructureTest.php`
- Modify: `tests/Feature/RootRouteTest.php`
- Modify: `tests/Feature/Production/ProductionHardeningTest.php`

**Interfaces:** O teste de arquitetura passa a ser a barreira contra reintrodução de tenancy/provisioning/Admin/Docker e contra infraestrutura sem consumidor.

- [ ] **Step 1: RED — reescrever `BackendScopeTest` para o escopo Supabase-only**

O teste deve exigir, no estado final:

```php
expect((string) file_get_contents(base_path('composer.json')))
    ->not->toContain('stancl/tenancy')
    ->not->toContain('laravel/sanctum');

expect(file_exists(config_path('tenancy.php')))->toBeFalse()
    ->and(file_exists(base_path('docker-compose.yml')))->toBeFalse()
    ->and(file_exists(app_path('Platform/Provisioning')))->toBeFalse()
    ->and(file_exists(app_path('Platform/Tenancy')))->toBeFalse()
    ->and(file_exists(resource_path('views/admin')))->toBeFalse()
    ->and(file_exists(database_path('migrations/central')))->toBeFalse()
    ->and(file_exists(database_path('migrations/tenant')))->toBeFalse();
```

Também verificar ausência, fora de `docs/superpowers/`, de `sislac_central`, `DB_ROOT_`, `TENANT_DB_`, `X-Tenant`, `TenantProvisioner`, `DB::connection('central')`, `DB::connection('tenant')`, `tenant_template` e `supabase_source`.

- [ ] **Step 2: RED — raiz e produção**

Alterar `RootRouteTest` para exigir resposta simples da API em `/` ou 204/JSON de identificação, nunca redirect para `/admin`. Atualizar `ProductionHardeningTest` para não ler `config/sanctum.php`, não mencionar proxy Docker e exigir Bearer Supabase + deploy nativo Nginx/PHP-FPM.

- [ ] **Step 3: verificar RED**

Run:

```bash
vendor/bin/pest tests/Feature/Architecture/BackendScopeTest.php tests/Feature/RootRouteTest.php tests/Feature/Production/ProductionHardeningTest.php
```

Expected: FAIL porque o runtime ainda contém tenancy, Admin, Docker e banco central.

- [ ] **Step 4: commit do contrato RED**

```bash
git add tests/Feature/Architecture tests/Feature/RootRouteTest.php tests/Feature/Production/ProductionHardeningTest.php
git commit -m "test: fixa arquitetura Laravel sobre Supabase"
```

---

### Task 2: Identidade Supabase, contexto PostgreSQL/RLS e autorização

**Files:**
- Modify: `app/Platform/Supabase/SupabaseAuthUser.php`
- Modify: `app/Http/Middleware/AuthenticateSupabaseUser.php`
- Create: `app/Http/Middleware/ApplySupabaseDatabaseContext.php`
- Create: `app/Http/Middleware/RequireSupabasePermission.php`
- Create: `app/Platform/Supabase/SupabasePermissionAuthorizer.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Feature/Auth/SupabaseBearerAuthenticationTest.php`
- Modify: `tests/Feature/Auth/AtendimentosSupabaseBearerContractTest.php`
- Modify: `tests/Feature/Security/AuthenticationSecurityTest.php`
- Replace: `tests/Feature/Concurrency/TenantContextConcurrencyTest.php` → `tests/Feature/Concurrency/SupabaseDatabaseContextTest.php`
- Replace: `tests/Feature/Security/PacienteAuthorizationTest.php` with Supabase permission tests

**Interfaces:**
- `supabase.auth`: valida Bearer e define o principal da request.
- `supabase.db`: envolve a request autenticada em transação com contexto RLS.
- `permission:<nome>`: chama a permissão canônica do Supabase.

- [ ] **Step 1: RED — principal Supabase sem usuário central**

O teste deve provar que um `/auth/v1/user` válido basta para formar o principal e que nenhum `central.users` é consultado/criado.

O principal deve oferecer pelo menos:

```php
$user->getAuthIdentifier(); // UUID do Supabase
$user->getKey();            // mesmo UUID
$user->getAttribute('email');
```

Manter 401 para Bearer ausente/rejeitado e 503 para indisponibilidade do Auth, sem vazar token ou resposta interna.

- [ ] **Step 2: RED — contexto RLS transacional**

Criar teste PostgreSQL que, dentro do middleware, prove:

```sql
select current_user; -- authenticated
select auth.uid();   -- UUID validado
```

E depois da request prove que a role/claims não vazam para a próxima transação.

- [ ] **Step 3: RED — autorização canônica**

`SupabasePermissionAuthorizer::allows($userId, $permission)` deve executar a função já existente no banco:

```sql
select public.has_permission(?::uuid, ?::text) as allowed
```

`RequireSupabasePermission` retorna 403 quando `false` e nunca lê `user_metadata`.

- [ ] **Step 4: GREEN — implementar principal e autenticação**

Remover `App\Platform\Models\User` de `AuthenticateSupabaseUser`. Definir o resolver diretamente com `SupabaseAuthUser`.

- [ ] **Step 5: GREEN — implementar contexto PostgreSQL**

`ApplySupabaseDatabaseContext` deve usar uma única `DB::transaction()` por request autenticada. Dentro dela, configurar contexto somente local à transação:

```php
DB::statement('SET LOCAL ROLE authenticated');
DB::select("select set_config('request.jwt.claim.sub', ?, true)", [$userId]);
DB::select("select set_config('request.jwt.claim.role', 'authenticated', true)");
DB::select("select set_config('request.jwt.claims', ?, true)", [json_encode([
    'sub' => $userId,
    'role' => 'authenticated',
], JSON_THROW_ON_ERROR)]);
```

A role de login de produção deve possuir somente os privilégios necessários para `SET ROLE authenticated`; nunca `BYPASSRLS`.

- [ ] **Step 6: GREEN — registrar aliases**

Em `bootstrap/app.php`, deixar somente aliases necessários ao backend clínico:

```php
'supabase.auth' => AuthenticateSupabaseUser::class,
'supabase.db' => ApplySupabaseDatabaseContext::class,
'permission' => RequireSupabasePermission::class,
```

- [ ] **Step 7: verificar GREEN**

Run:

```bash
vendor/bin/pest tests/Feature/Auth tests/Feature/Security/AuthenticationSecurityTest.php tests/Feature/Security/PacienteAuthorizationTest.php tests/Feature/Concurrency/SupabaseDatabaseContextTest.php
```

Expected: PASS.

- [ ] **Step 8: commit**

```bash
git add app/Http/Middleware app/Platform/Supabase bootstrap/app.php tests/Feature/Auth tests/Feature/Security tests/Feature/Concurrency
git commit -m "refactor: usa identidade e RLS nativos do Supabase"
```

---

### Task 3: Remover plano central, tenancy, Super Admin e dependências órfãs

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `bootstrap/providers.php`
- Modify: `config/database.php`
- Modify: `config/cache.php`
- Modify: `config/session.php`
- Modify: `config/queue.php`
- Modify: `.env.example`
- Modify: `routes/web.php`
- Modify: `routes/console.php`
- Delete: `config/tenancy.php`
- Delete: `config/provisioning.php`
- Delete: `config/sanctum.php`
- Delete: `app/Providers/PlatformServiceProvider.php`
- Delete: `app/Providers/TenancyServiceProvider.php`
- Delete: `app/Platform/Authorization/**`
- Delete: `app/Platform/Models/**`
- Delete: `app/Platform/Provisioning/**`
- Delete: `app/Platform/Queries/**`
- Delete: `app/Platform/Tenancy/**`
- Delete: `app/Http/Middleware/EnsureTenantContext.php`
- Delete: `app/Http/Middleware/RequireTenantPermission.php`
- Delete: `app/Http/Middleware/RequireSuperAdmin.php`
- Delete: `app/Http/Controllers/Admin/**`
- Delete: `app/Http/Requests/Admin/**`
- Delete: `resources/views/admin/**`
- Delete: `database/factories/UserFactory.php` if no remaining consumer
- Delete: `database/migrations/central/**`
- Delete: `database/migrations/tenant/**`
- Delete: `database/migrations/0001_01_01_000001_create_cache_table.php`

- [ ] **Step 1: varredura antes da exclusão**

Run:

```bash
rg -n "stancl|Sanctum|Platform\\\\Models|Platform\\\\Authorization|TenantProvisioner|PostgresDatabaseAdmin|tenancy\(|X-Tenant|DB::connection\(['\"]central|DB::connection\(['\"]tenant" app bootstrap config routes database resources tests composer.json
```

Classificar cada ocorrência como `remover`, `adaptar` ou `histórico`; nenhuma ocorrência runtime pode ser simplesmente ignorada.

- [ ] **Step 2: simplificar configuração de banco**

A conexão default final é `pgsql`, apontando para Supabase via `DB_*`, com SSL obrigatório por padrão:

```php
'default' => env('DB_CONNECTION', 'pgsql'),
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'postgres'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'sslmode' => env('DB_SSLMODE', 'require'),
    'search_path' => 'public',
],
```

SQLite pode permanecer somente se houver teste unitário que realmente o use; não é conexão de produção.

- [ ] **Step 3: cache/session/queue mínimos**

Usar `CACHE_STORE=file`, `SESSION_DRIVER=file` se sessão de framework ainda for necessária internamente, e `QUEUE_CONNECTION=sync`. Remover stores/conexões `database` de cache/queue quando não houver consumidor.

- [ ] **Step 4: remover Super Admin web**

`routes/web.php` deve deixar apenas uma resposta simples para `/`, por exemplo:

```php
Route::get('/', fn () => response()->json(['service' => 'SISLAC API']));
```

Remover controllers, requests, views e comando `admin:super-user`.

- [ ] **Step 5: remover pacotes explicitamente**

Depois da varredura confirmar ausência de consumidor:

```bash
composer remove stancl/tenancy laravel/sanctum --no-interaction
```

Remover `laravel/boost`, `laravel/pail` e `laravel/pao` somente se `rg` provar que não há configuração/script/fluxo usado. Não remover Pest, Pint, Larastan, Mockery ou Collision.

- [ ] **Step 6: remover migrations duplicadas**

Nenhuma migration Laravel deve recriar `pacientes`, `atendimentos`, financeiro, `users`, `tenants`, cache ou qualquer tabela do Supabase.

- [ ] **Step 7: verificar arquitetura**

Run:

```bash
composer validate --strict --no-interaction
vendor/bin/pest tests/Feature/Architecture/BackendScopeTest.php tests/Feature/RootRouteTest.php tests/Feature/Production/ProductionHardeningTest.php
```

Expected: PASS para fundação simplificada.

- [ ] **Step 8: commit**

```bash
git add -A
git commit -m "refactor: remove tenancy provisioning e Super Admin legado"
```

---

### Task 4: Adaptar rotas e Pacientes para a conexão Supabase única

**Files:**
- Modify: `routes/api.php`
- Keep/Modify as needed: `app/Domain/Pacientes/**`
- Keep/Modify as needed: `app/Http/Controllers/Pacientes/**`
- Modify: `tests/Feature/Domain/Pacientes/PacienteReadApiTest.php`
- Modify: `tests/Feature/Domain/Pacientes/PacienteWriteApiTest.php`
- Modify: `tests/Feature/Domain/Pacientes/PacienteFriendlyIdTest.php`
- Keep/Modify: `tests/Feature/Performance/PacienteQueryPerformanceTest.php`

- [ ] **Step 1: RED — rotas sem tenancy**

Pacientes deve usar a cadeia:

```php
['supabase.auth', 'supabase.db', 'permission:visualizar_pacientes']
['supabase.auth', 'supabase.db', 'permission:cadastrar_paciente']
['supabase.auth', 'supabase.db', 'permission:editar_paciente']
```

O teste deve falhar enquanto `tenant`/`tenant.permission` ainda estiverem nas rotas.

- [ ] **Step 2: refatorar testes de banco**

Eliminar `CREATE DATABASE`, `Tenant`, `membership`, `tenancy()->initialize()` e migrations Laravel. Os testes de integração devem usar um único PostgreSQL de teste com fixture mínima compatível com o schema Supabase.

Criar `tests/Fixtures/supabase-test-schema.sql` somente com objetos requeridos pelos testes, incluindo `pacientes`, `friendly_id_counters`, função `has_permission` de teste e demais tabelas necessárias às ondas mantidas. A fixture não é migration de produção.

- [ ] **Step 3: GREEN — preservar regras úteis**

Manter `Paciente`, `CreatePaciente`, `UpdatePaciente` e `PacienteFriendlyId` usando a conexão default. Preservar CPF único, `friendly_id`, normalização, LGPD, paginação e validação que correspondam ao Supabase atual.

- [ ] **Step 4: verificar Pacientes**

Run:

```bash
vendor/bin/pest tests/Feature/Domain/Pacientes tests/Feature/Performance/PacienteQueryPerformanceTest.php tests/Feature/Security/PacienteAuthorizationTest.php
```

Expected: PASS sem banco tenant.

- [ ] **Step 5: commit**

```bash
git add routes/api.php app/Domain/Pacientes app/Http/Controllers/Pacientes tests/Feature/Domain/Pacientes tests/Feature/Performance tests/Fixtures
git commit -m "refactor: conecta Pacientes diretamente ao Supabase"
```

---

### Task 5: Adaptar Atendimentos e Rotina sem contexto tenant

**Files:**
- Modify: `app/Domain/Atendimentos/**`
- Modify: `app/Http/Controllers/Atendimentos/**`
- Modify: `app/Http/Controllers/Rotina/**`
- Modify: `app/Http/Requests/Atendimentos/**`
- Modify: `app/Http/Requests/Rotina/**`
- Modify: tests under `tests/Feature/Domain/Atendimentos/**`
- Modify: tests under `tests/Feature/Domain/Rotina/**`
- Modify: `tests/Feature/Security/AtendimentoAuthorizationTest.php`
- Modify: `tests/Feature/Security/AtendimentoCreatePaymentBoundaryTest.php`
- Modify: `tests/Feature/Concurrency/RotinaConcurrencyTest.php`

- [ ] **Step 1: RED — remover dependência de tenant das Actions/Controllers**

Adicionar/ajustar testes que falhem enquanto existirem `DB::connection('tenant')`, `tenant_id`, `MembershipAuthorizer` ou `TenantPermission`.

- [ ] **Step 2: GREEN — conexão default**

Em `UpdateRotinaConfig`, `ShowRotinaConfigController` e qualquer ocorrência equivalente, trocar a conexão explícita tenant por `DB::transaction`, `DB::table`, `DB::statement` ou Eloquent default.

- [ ] **Step 3: GREEN — autorização dinâmica de Atendimentos**

`UpdateAtendimentoController` deve consultar `SupabasePermissionAuthorizer` diretamente para `editar_atendimento` e/ou `cancelar_atendimento`, usando somente o UUID do principal Supabase.

`TransitionRotinaExameController` deve mapear:

```text
coletar/recoletar -> registrar_coleta
iniciar_analise/finalizar_analise -> analisar_amostra
cancelar -> cancelar_atendimento
```

sem tenant id.

- [ ] **Step 4: preservar invariantes**

Não enfraquecer transações, `lockForUpdate`, idempotência, cancelamento, auditoria, protocolo, janela operacional ou normalizações de fluxo que já são responsabilidade do Laravel.

- [ ] **Step 5: verificar**

Run:

```bash
vendor/bin/pest tests/Feature/Domain/Atendimentos tests/Feature/Domain/Rotina tests/Feature/Security/AtendimentoAuthorizationTest.php tests/Feature/Security/AtendimentoCreatePaymentBoundaryTest.php tests/Feature/Concurrency/RotinaConcurrencyTest.php
```

Expected: PASS sem criação de banco físico.

- [ ] **Step 6: commit**

```bash
git add app/Domain/Atendimentos app/Http/Controllers/Atendimentos app/Http/Controllers/Rotina app/Http/Requests/Atendimentos app/Http/Requests/Rotina tests/Feature/Domain/Atendimentos tests/Feature/Domain/Rotina tests/Feature/Security tests/Feature/Concurrency
git commit -m "refactor: adapta Atendimentos e Rotina ao Supabase único"
```

---

### Task 6: Adaptar Financeiro e preservar hardening já aprovado

**Files:**
- Modify: `app/Domain/Financeiro/**`
- Modify: `app/Http/Controllers/Financeiro/**`
- Modify: `app/Http/Requests/Financeiro/**`
- Modify: tests under `tests/Feature/Domain/Financeiro/**`
- Remove schema-only tests that test Laravel migrations rather than runtime behavior

- [ ] **Step 1: RED — proibir `DB::connection('tenant')` no Financeiro**

Cobrir `ListAReceberPacientes`, `ListRecebimentosPacientes` e todas as Actions/Queries restantes.

- [ ] **Step 2: GREEN — conexão default e principal Supabase**

Usar a conexão default. Onde houver auditoria, obter UUID/e-mail do principal Supabase, sem `Platform\Models\User` ou membership.

- [ ] **Step 3: preservar controles financeiros críticos**

Manter como regressão obrigatória:

- exames cancelados não entram em totais/recebíveis;
- pagamentos estornados não contam como recebidos;
- pagamento não ultrapassa saldo;
- atendimento cancelado não recebe pagamento;
- saídas/despesas não usam DELETE físico no fluxo HTTP;
- estornos são explícitos/auditáveis;
- abertura/fechamento de caixa continuam transacionais;
- concorrência continua protegida onde houver `lockForUpdate`.

- [ ] **Step 4: remover testes de schema duplicado**

Excluir `FinanceiroCoreSchemaTest`, `CaixaOperacionalSchemaTest`, `FinanceiroSaidasSchemaTest` e equivalentes cujo único propósito seja provar migrations Laravel removidas. Substituir apenas as invariantes funcionais realmente necessárias por testes contra a fixture PostgreSQL de integração.

- [ ] **Step 5: verificar Financeiro**

Run:

```bash
vendor/bin/pest tests/Feature/Domain/Financeiro
```

Expected: PASS.

- [ ] **Step 6: commit**

```bash
git add -A app/Domain/Financeiro app/Http/Controllers/Financeiro app/Http/Requests/Financeiro tests/Feature/Domain/Financeiro tests/Fixtures
git commit -m "refactor: simplifica Financeiro sobre Supabase"
```

---

### Task 7: Limpeza física de código, testes, contratos e infraestrutura obsoletos

**Files:**
- Delete: `docker-compose.yml`
- Delete: `docker/**`
- Delete: `docs/PGADMIN.md`
- Delete: `docs/CONECTAR_DBEAVER.md`
- Delete: `tests/Feature/Admin/**`
- Delete: `tests/Feature/Provisioning/**`
- Delete: `tests/Feature/Tenancy/**`
- Delete: `tests/Unit/Platform/TenantSelectionTest.php`
- Delete: `tests/Feature/Performance/TenantResolutionPerformanceTest.php`
- Delete: `tests/Feature/Security/TenantSecurityTest.php`
- Delete or reduce: old `app/Platform/Supabase/*Contract*`, `SupabaseSource.php`, `scripts/check-supabase-contract.php`, `docs/contracts/**` when their only purpose was comparar a cópia Laravel com a origem Supabase
- Delete: `scripts/check-no-central-in-tenant.sh`
- Delete: `scripts/check-postgres-bootstrap.sh`
- Modify: `scripts/check-backend-scope.sh`
- Keep/Modify: `scripts/check-database-contract.sh`

- [ ] **Step 1: confirmar consumidores dos contratos antigos**

Run:

```bash
rg -n "MigratedContractsLiveContract|PacientesLiveContract|SupabaseContractRegistry|SupabaseSource|docs/contracts|check-supabase-contract" app routes scripts tests .github README.md docs --glob '!docs/superpowers/**'
```

Se só houver gates da migração antiga, remover o conjunto inteiro. Não manter manifesto/registry apenas por histórico operacional.

- [ ] **Step 2: apagar testes da arquitetura extinta**

Remover todos os testes que criam/destroem bancos tenant, testam membership central, provisioning, Super Admin local ou migrations duplicadas.

- [ ] **Step 3: apagar Docker/pgAdmin**

Como o deploy final é Nginx + PHP-FPM nativos e Supabase remoto, remover `docker-compose.yml` e `docker/**` integralmente.

- [ ] **Step 4: reescrever o guard de escopo**

`scripts/check-backend-scope.sh` deve falhar se runtime/operacional reintroduzir:

```text
stancl/tenancy
sislac_central
TenantProvisioner
PostgresDatabaseAdmin
DB_ROOT_
TENANT_DB_
X-Tenant
DB::connection('central')
DB::connection('tenant')
tenant_template
supabase_source
resources/views/admin
docker-compose.yml
docker/
```

Excluir `docs/superpowers/**` da busca porque são histórico deliberado.

- [ ] **Step 5: remover diretórios vazios e artefatos**

Verificar `.gitkeep`, caches, logs, ZIPs, dumps, arquivos temporários e artefatos gerados. `vendor/`, `.env` e `storage/framework/*` nunca entram no commit.

- [ ] **Step 6: verificar guard**

Run:

```bash
bash scripts/check-backend-scope.sh
bash scripts/check-database-contract.sh
bash scripts/check-file-size.sh
```

Expected: PASS.

- [ ] **Step 7: commit**

```bash
git add -A
git commit -m "chore: remove infraestrutura e legado sem consumidor"
```

---

### Task 8: CI, ambiente e documentação operacional definitivos

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `.env.example`
- Modify: `README.md`
- Modify: `docs/ARCHITECTURE.md`
- Modify: `docs/DEPLOY.md`
- Modify: `docs/SEGURANCA.md`
- Modify: `app/Http/Controllers/HealthController.php`
- Modify: `tests/Feature/HealthTest.php`

- [ ] **Step 1: simplificar CI**

PostgreSQL 17 continua somente como um banco único de integração, por exemplo:

```yaml
POSTGRES_USER: sislac_test
POSTGRES_PASSWORD: sislac_test
POSTGRES_DB: sislac_test
```

Env do job:

```yaml
DB_CONNECTION: pgsql
DB_HOST: 127.0.0.1
DB_PORT: 5432
DB_DATABASE: sislac_test
DB_USERNAME: sislac_test
DB_PASSWORD: sislac_test
DB_SSLMODE: disable
```

Carregar `tests/Fixtures/supabase-test-schema.sql` antes dos testes PostgreSQL. Não existirão `TENANT_DB_*` nem bootstrap Docker.

- [ ] **Step 2: manter gates úteis**

CI final executa:

```bash
composer validate --strict --no-interaction
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
bash scripts/check-backend-scope.sh
bash scripts/check-database-contract.sh
bash scripts/check-file-size.sh
```

E mantém guard para nenhum `.env` versionado.

- [ ] **Step 3: `.env.example` mínimo**

Conter somente variáveis realmente consumidas, incluindo:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://sislac-api.test
APP_TIMEZONE=America/Sao_Paulo

DB_CONNECTION=pgsql
DB_HOST=
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=
DB_PASSWORD=
DB_SSLMODE=require

SUPABASE_URL=
SUPABASE_PUBLISHABLE_KEY=

CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

FRONTEND_URL=https://sislac.com.br
CORS_ALLOWED_ORIGINS=https://sislac.com.br,https://www.sislac.com.br,http://localhost:5173
```

Não inserir senha real nem URL secreta.

- [ ] **Step 4: Health check**

Atualizar comentário/semântica para “banco Supabase”/“conexão default”, sem menção a central. Continuar sem expor versão de PHP/Laravel ou credenciais.

- [ ] **Step 5: reescrever docs operacionais**

`README`, `ARCHITECTURE`, `DEPLOY`, `SEGURANCA` devem contar uma única história:

```text
React/Vercel -> Laravel API -> Supabase Auth/PostgreSQL/Storage
```

Deploy: Ubuntu 24.04 + Nginx + PHP 8.4-FPM + Composer + Git; sem Docker e sem PostgreSQL local.

Documentar a role PostgreSQL dedicada do backend e a exigência de `SET ROLE authenticated`, sem fornecer senha nem instrução que conceda `BYPASSRLS`.

- [ ] **Step 6: verificar docs/CI**

Run:

```bash
vendor/bin/pest tests/Feature/Architecture tests/Feature/Production tests/Feature/HealthTest.php
bash scripts/check-backend-scope.sh
```

Expected: PASS.

- [ ] **Step 7: commit**

```bash
git add .github/workflows/ci.yml .env.example README.md docs/ARCHITECTURE.md docs/DEPLOY.md docs/SEGURANCA.md app/Http/Controllers/HealthController.php tests/Feature/HealthTest.php scripts
git commit -m "docs: consolida backend Laravel sobre Supabase"
```

---

### Task 9: Verificação final, varredura de órfãos e PR

- [ ] **Step 1: instalar exatamente o lock final**

```bash
composer install --no-interaction --prefer-dist
```

Expected: instalação limpa em PHP 8.4.

- [ ] **Step 2: gates completos no mesmo SHA**

```bash
composer validate --strict --no-interaction
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
bash scripts/check-backend-scope.sh
bash scripts/check-database-contract.sh
bash scripts/check-file-size.sh
```

Expected: todos PASS.

- [ ] **Step 3: varredura explícita de resíduos**

Run:

```bash
rg -n "stancl|TenantProvisioner|PostgresDatabaseAdmin|sislac_central|DB_ROOT_|TENANT_DB_|X-Tenant|connection\(['\"]tenant|connection\(['\"]central|tenant_template|supabase_source|/admin|pgadmin|docker compose" \
  app bootstrap config routes database resources scripts tests README.md docs/ARCHITECTURE.md docs/DEPLOY.md docs/SEGURANCA.md .env.example composer.json .github/workflows/ci.yml
```

Expected: nenhum hit funcional. Hits históricos são permitidos somente dentro de `docs/superpowers/**`, que não participa desse comando.

- [ ] **Step 4: busca por arquivos mortos e diretórios vazios**

```bash
find app bootstrap config routes database resources scripts tests -type d -empty -print
find . -type f \( -name '*.tmp' -o -name '*.bak' -o -name '*.zip' -o -name '*.log' \) -not -path './vendor/*' -not -path './storage/*' -print
```

Expected: nenhum resíduo versionado não intencional.

- [ ] **Step 5: revisar diff e status**

```bash
git diff main...HEAD --stat
git diff main...HEAD --check
git status --short
```

Expected: `diff --check` sem erro e working tree limpa.

- [ ] **Step 6: validar CI remoto no SHA final**

Push do branch e aguardar `Qualidade PHP` + `Guards de repositório`. Não declarar conclusão antes de ambos passarem no mesmo SHA.

- [ ] **Step 7: abrir PR sem merge automático**

Título sugerido:

```text
refactor: simplifica Laravel para backend do Supabase
```

Corpo deve resumir arquitetura removida, arquitetura final, módulos preservados, gates executados e qualquer passo operacional ainda necessário para criar/configurar a role PostgreSQL dedicada. O merge fica para decisão explícita após revisão do PR.

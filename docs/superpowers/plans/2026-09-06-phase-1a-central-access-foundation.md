# Fase 1A — Fundação do plano central e acesso a tenant

> **Execução:** seguir TDD em cada comportamento. Nenhum código de produção entra antes de um teste RED comprovado. Cada task termina com CI verde, revisão do diff e commit pequeno.

**Goal:** construir a primeira fundação executável do plano central do SISLAC, preservando identidade UUID do Supabase, preparando a resolução segura de laboratório e criando um contrato de concordância mensurável sem introduzir dependências Composer que não possam ter o `composer.lock` regenerado legitimamente.

**Architecture:** o plano central permanece no PostgreSQL `sislac_central`; entidades de plataforma vivem em `app/Platform`; identidade e tenant usam UUID para interoperar com os UUIDs atuais de `auth.users`/`profiles`; a seleção de tenant é uma regra explícita baseada em memberships. A integração efetiva com Sanctum e `stancl/tenancy` fica na Fase 1B, pois exige instalação oficial de pacotes e regeneração real do lockfile.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Pest 4, Pint. CI com PostgreSQL real. Supabase atual usado apenas como referência comportamental e de schema.

**Spec:** `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`

## Global Constraints

- Documentação oficial Laravel 13, Supabase/PostgreSQL e `stancl/tenancy` prevalece sobre código legado.
- PostgreSQL é obrigatório; nenhuma implementação nova pode depender de SQLite/MySQL.
- `app/Platform` nunca referencia `App\Domain`; `app/Domain` nunca referencia `App\Platform`.
- IDs de usuário são UUID porque `auth.users.id` e `public.profiles.id` do Supabase atual são UUID.
- Tenant é selecionado por membership ativa. `X-Tenant` nunca autoriza por si só.
- Nenhum segredo, dump ou dado clínico real nos testes.
- Auditoria de plataforma é append-only por contrato de aplicação e será endurecida no banco quando o fluxo de auditoria for implementado.
- Nomes de classes/métodos expressam regra de negócio; evitar helpers genéricos, service locators e abstrações sem consumidor real.
- Preferir constraints e índices do PostgreSQL para invariantes estruturais; não duplicar invariantes apenas em PHP.
- Todo comportamento novo: RED comprovado → implementação mínima → GREEN → refactor mantendo GREEN.
- Nenhum `composer.json` será alterado sem `composer.lock` gerado por Composer.
- O template de banco existente chamado `tenant` será renomeado para `tenant_template` apenas na Fase 1B, junto da instalação oficial do `stancl/tenancy`, porque a documentação do pacote reserva `tenant` para a conexão gerenciada por ele.

## Task 1 — Schema central mínimo e identidade UUID

**Files**
- Create: `tests/Feature/Platform/CentralSchemaTest.php`
- Move/recreate: `database/migrations/0001_01_01_000000_create_users_table.php` → `database/migrations/central/0001_01_01_000000_create_users_table.php`
- Create: `database/migrations/central/2026_09_06_000100_create_platform_tables.php`
- Create: `app/Providers/PlatformServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Move/recreate: `app/Models/User.php` → `app/Platform/Models/User.php`
- Modify: `config/auth.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Delete: `app/Models/User.php`

### RED

Criar teste que use `RefreshDatabase` e valide no PostgreSQL central:

```php
it('cria o schema central mínimo da plataforma', function () {
    $schema = Schema::connection('central');

    foreach (['users', 'tenants', 'plans', 'memberships', 'subscriptions', 'provisioning_runs', 'platform_audit'] as $table) {
        expect($schema->hasTable($table))->toBeTrue("Tabela central ausente: {$table}");
    }
});
```

Adicionar teste que confirme `users.id` e `tenants.id` como `uuid` consultando `information_schema.columns` no PostgreSQL. A mudança de produção que deve fazê-lo falhar é a migração atual de `users`, que usa `bigint`, e a ausência de `tenants`.

**Verify RED:** CI do PR deve falhar no Pest por tabela ausente/tipo incorreto, não por erro de sintaxe/setup.

### GREEN

`PlatformServiceProvider::boot()` carrega somente `database/migrations/central` via `loadMigrationsFrom`. O migration de `users` usa:

```php
$table->uuid('id')->primary();
$table->string('name');
$table->string('email')->unique();
$table->timestamp('email_verified_at')->nullable();
$table->string('password');
$table->rememberToken();
$table->timestamps();
```

`User` passa a `App\Platform\Models\User`, declara conexão `central`, usa `HasUuids`, `HasFactory`, `Notifiable`, fillable explícito e casts oficiais do Laravel.

A migration de plataforma cria:

- `tenants`: UUID PK, `name`, `code` único, `status` indexado, `database_name` único, timestamps.
- `plans`: bigint PK, `name`, `slug` único, `active`, timestamps.
- `memberships`: bigint PK, UUID FKs para user/tenant, `role`, `status`, timestamps, unique `(user_id, tenant_id)`, índices `(user_id,status)` e `(tenant_id,status)`.
- `subscriptions`: bigint PK, UUID FK tenant, FK plan, `status`, datas nullable e timestamps; uma assinatura ativa será regra de serviço futura, não constraint parcial inventada nesta task.
- `provisioning_runs`: bigint PK, UUID tenant, `status`, `schema_version` nullable, `started_at`, `finished_at`, `duration_ms`, `error_code`/`error_message` sanitizados nullable, timestamps; índice `(tenant_id, created_at)`.
- `platform_audit`: bigint PK, UUID actor nullable, `action`, `subject_type`, `subject_id` string nullable, `metadata` jsonb nullable, `ip_address` nullable, `created_at`; sem `updated_at`/soft delete.

Não armazenar senha/host/credencial de banco em `tenants`.

**Verify GREEN:** Pint + Pest completo + guards no CI.

## Task 2 — Invariantes relacionais do plano central

**Files**
- Create: `tests/Feature/Platform/CentralConstraintsTest.php`
- Modify only if tests expose a gap: `database/migrations/central/2026_09_06_000100_create_platform_tables.php`

### RED

Testar com inserts reais no PostgreSQL:

1. membership duplicada para mesmo `(user_id, tenant_id)` deve lançar violação unique;
2. membership com user inexistente deve falhar FK;
3. membership com tenant inexistente deve falhar FK;
4. `tenant.code` duplicado deve falhar;
5. `database_name` duplicado deve falhar;
6. exclusão de tenant com membership deve ser restringida enquanto não houver fluxo explícito de offboarding.

A alteração de produção que faria cada teste passar deve ser uma constraint/index do banco, nunca um `if` no model.

**Verify RED/GREEN:** rodar teste alvo e suíte completa no CI.

## Task 3 — Regra pura de seleção de tenant por membership

**Files**
- Create: `tests/Unit/Platform/TenantSelectionTest.php`
- Create: `app/Platform/Tenancy/TenantSelection.php`
- Create: `app/Platform/Tenancy/TenantSelectionResult.php` somente se o teste demonstrar necessidade; caso contrário retornar UUID/string e exceptions específicas.
- Create: `app/Platform/Tenancy/Exceptions/NoActiveMembership.php`
- Create: `app/Platform/Tenancy/Exceptions/TenantSelectionRequired.php`
- Create: `app/Platform/Tenancy/Exceptions/TenantNotAuthorized.php`

### Contract

Entrada: lista imutável de IDs de tenants de memberships **ativas** + `requestedTenantId` nullable.

Regras:

- 0 memberships → `NoActiveMembership`;
- 1 membership + header ausente → retorna o único tenant;
- 1 membership + header igual → retorna o único tenant;
- 1 membership + header diferente → `TenantNotAuthorized`;
- >1 memberships + header ausente → `TenantSelectionRequired`;
- >1 memberships + header pertencente à lista → retorna o solicitado;
- >1 memberships + header forjado → `TenantNotAuthorized`.

A classe não acessa HTTP, banco, facade ou container. É uma política pura, determinística, legível e exaustivamente testada.

**RED:** testes unitários referenciam a API desejada antes da classe existir.
**GREEN:** implementação mínima usando comparações estritas e lista deduplicada.

## Task 4 — Consulta central eficiente de memberships ativas

**Files**
- Create: `tests/Feature/Platform/ActiveMembershipsQueryTest.php`
- Create: `app/Platform/Models/Tenant.php`
- Create: `app/Platform/Models/Membership.php`
- Create: `app/Platform/Queries/ActiveTenantMemberships.php`

### RED

Com fixtures sintéticas:

- retorna apenas memberships ativas do usuário;
- nunca retorna membership de outro usuário;
- nunca retorna tenant suspenso/inativo;
- executa em uma consulta SQL previsível, sem N+1;
- `EXPLAIN` do lookup principal deve utilizar índice apropriado no PostgreSQL para volume sintético suficiente.

### GREEN

Query object pequeno que devolve apenas os IDs necessários para `TenantSelection`. Nada de carregar models/relacionamentos sem necessidade.

Não criar cache nesta task: autorização precisa refletir suspensão imediatamente; cache só poderá ser introduzido posteriormente com estratégia explícita de invalidação.

## Task 5 — Manifesto de concordância e gates de qualidade da Fase 1A

**Files**
- Create: `docs/conformance/supabase-runtime-baseline.json`
- Create: `tests/Feature/Conformance/RuntimeBaselineTest.php`
- Modify: `.github/workflows/ci.yml`
- Modify: `docs/ARCHITECTURE.md`
- Modify: `docs/SEGURANCA.md`

### Manifest baseline

Registrar sem segredos/dados:

- frontend SHA observado;
- Supabase project ref público já conhecido pelo projeto;
- PostgreSQL major;
- contagem física: 96 tables, 2 views, 6 enums;
- RLS: 96/96 tabelas públicas;
- categorias contratuais `required-runtime`, `required-compat`, `platform-specific`, `dead-or-legacy` inicialmente vazias/explicitamente não classificadas, nunca preenchidas por palpite;
- findings de advisors apenas por código/nome, sem conteúdo sensível.

### CI

Adicionar `composer audit --locked --no-interaction` como gate quando suportado pelo Composer v2 do runner. Manter Pint/Pest/guards. Larastan continua não-bloqueante até o pacote ser instalado legitimamente na 1B; não fingir análise estática.

### Docs

Corrigir afirmação antiga de bearer para SPA:

- SPA first-party: Sanctum stateful cookie + CSRF, conforme Laravel 13;
- bearer token: somente integrações/mobile/terceiros quando necessário;
- `tokenCan()` não substitui policies/autorização de negócio.

Registrar também que `stancl/tenancy` reserva a conexão `tenant`; `tenant_template` será o nome do template na 1B.

## Exit Criteria da Fase 1A

A Fase 1A só pode ser declarada concluída quando:

1. cada task tiver evidência RED e GREEN;
2. CI do PR estiver verde no HEAD final;
3. migrations rodarem em PostgreSQL real do zero;
4. IDs compatíveis com UUID do Supabase forem comprovados;
5. constraints de membership forem provadas no banco;
6. política de seleção de tenant estiver exaustivamente testada;
7. lookup de autorização não tiver N+1 e tiver plano de consulta indexado;
8. `composer audit` estiver verde;
9. nenhum arquivo de produção depender de pacote ausente;
10. diff final passar por revisão de conformidade/legibilidade antes do merge.

## Fase 1B — deliberadamente fora deste plano

Só inicia após 1A verde. Executará, por comandos oficiais e com lockfile real:

1. `composer require laravel/boost --dev` + `php artisan boost:install`;
2. `php artisan install:api` para Sanctum, conforme Laravel 13;
3. instalação de versão de `stancl/tenancy` comprovadamente compatível + `php artisan tenancy:install`;
4. renomear template `tenant` → `tenant_template` e configurar `tenancy.central_connection=central`;
5. middleware HTTP que combina autenticação central + memberships + `TenantSelection` + inicialização do pacote;
6. testes de isolamento A/B, CSRF/session, headers forjados, cleanup de contexto, cache/filesystem/queue tenancy;
7. provisioning multi-database com PostgreSQL sem conceder `CREATEDB` ao usuário HTTP.

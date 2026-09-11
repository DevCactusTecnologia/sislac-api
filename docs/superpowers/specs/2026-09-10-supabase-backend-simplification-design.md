# Simplificação do `sislac-api` — Laravel como backend do Supabase

Data: 2026-09-10

## 1. Objetivo

Reduzir o `sislac-api` ao papel que o produto precisa agora: uma API Laravel enxuta entre o frontend e o Supabase existente.

O Laravel continuará responsável por autenticação server-side do Bearer Supabase, autorização, validação, regras de negócio, transações, idempotência, auditoria e integrações. O Supabase continuará sendo a fonte de verdade de PostgreSQL, Auth e Storage.

A mudança remove a arquitetura futura de banco central + database-per-lab + provisionamento físico, porque ela não corresponde ao runtime atual e adiciona custo operacional, código, testes e infraestrutura sem consumidor atual.

## 2. Baseline confirmada

### Supabase / frontend

- O runtime atual é efetivamente **single-tenant**.
- O schema de origem não possui `tenants`/`memberships`.
- Identidade e autorização atuais usam Supabase Auth, `profiles`, `user_roles`, `lab_config` e funções/policies de permissão como `has_permission`.
- O frontend já usa permissões canônicas como `visualizar_pacientes`, `editar_paciente`, `visualizar_atendimentos`, `gestao_financeira` etc.
- O papel `super_admin` pode continuar existindo no Supabase; o que será removido é o **segundo plano administrativo Laravel** baseado em banco central próprio.

### `sislac-api`

Hoje o repositório contém responsabilidades que deixam de fazer sentido no novo escopo:

- conexão `central` e banco `sislac_central`;
- `stancl/tenancy`;
- `Tenant`, `memberships`, seleção por `X-Tenant` e autorização central;
- `TenantProvisioner` e criação/remoção de bancos físicos;
- `DB_ROOT_*` e `TENANT_DB_*`;
- migrations centrais e migrations de tenant usadas para construir bancos próprios;
- Super Admin Blade/local com login separado;
- testes que criam e removem bancos PostgreSQL por laboratório;
- guards, scripts e documentação dedicados à arquitetura database-per-lab;
- Docker Compose criado principalmente para PostgreSQL local + banco central + bancos físicos.

Ao mesmo tempo, os módulos de domínio já implementados — Pacientes, Atendimentos, Rotina e Financeiro — contêm regras úteis e devem ser reaproveitados quando estiverem compatíveis com o schema real do Supabase.

## 3. Abordagens consideradas

### A. Manter database-per-lab e usar Supabase só como origem transitória

Vantagem: preserva o desenho atual do repositório.

Desvantagens: mantém dois bancos, dois modelos de identidade, provisioning, tenancy, migrations duplicadas e operação significativamente mais cara.

**Rejeitada.**

### B. Laravel chamar somente a Data API/PostgREST do Supabase

Vantagem: RLS funciona naturalmente com o Bearer do usuário e não há credencial PostgreSQL no Laravel.

Desvantagens: exigiria reescrever boa parte das Actions/Queries já implementadas para HTTP e empurrar transações complexas para RPCs, aumentando indireção sem necessidade imediata.

**Não selecionada como caminho principal.** Pode ser usada pontualmente para recursos que dependam especificamente da Data API.

### C. Laravel conectar diretamente ao PostgreSQL do Supabase, preservando Supabase Auth e o contexto RLS

Vantagens: mantém Eloquent/Query Builder/transações, reaproveita o domínio já migrado e elimina banco central, tenancy e provisioning.

O Laravel valida o access token no Supabase Auth e executa cada request autenticado dentro de contexto PostgreSQL associado ao mesmo usuário, usando uma role dedicada e sem privilégios administrativos.

**Selecionada.**

## 4. Arquitetura final desta fase

```text
Frontend React / Vercel
        |
        | Authorization: Bearer <Supabase access token>
        v
Laravel API
        |
        | valida token no Supabase Auth
        | aplica validação + regra de negócio
        | estabelece contexto PostgreSQL do usuário
        v
Supabase
  - PostgreSQL
  - Auth
  - Storage
  - RLS / funções existentes
```

Não existirão banco central Laravel, banco físico por laboratório, `X-Tenant`, provisioning de banco ou identidade clínica duplicada.

## 5. Banco de dados

### 5.1 Uma conexão de aplicação

`config/database.php` terá uma única conexão PostgreSQL de produção para o Supabase, além de conexões estritamente necessárias a testes.

A conexão de produção usará variáveis convencionais `DB_*` e SSL obrigatório. Não haverá `central`, `tenant_template` nem `supabase_source` separado.

### 5.2 Role dedicada

Produção não usará `postgres`, `supabase_admin`, `service_role` nem uma role com `BYPASSRLS` como credencial HTTP da aplicação.

Será criada uma role PostgreSQL dedicada, por exemplo `sislac_backend`, com somente o necessário para conectar e assumir o contexto `authenticated` durante requests validados.

A senha dessa role existe somente no ambiente seguro do servidor.

### 5.3 Contexto RLS por request

Após validar o Bearer no Supabase Auth, o Laravel abre a transação da request e configura contexto local do usuário (`sub`/claims e role `authenticated`) usando `SET LOCAL`/`set_config`. O contexto é sempre transacional para não vazar entre conexões reutilizadas.

Isso permite que policies atuais baseadas em `auth.uid()`/`has_permission` continuem funcionando como defesa no banco, enquanto o Laravel também aplica autorização no endpoint.

Requests sem Bearer não recebem contexto de usuário.

### 5.4 Schema

O `sislac-api` deixa de ser proprietário de um schema paralelo.

Enquanto o Supabase atual continuar sendo a fonte de verdade, alterações de schema/RLS/funções permanecem versionadas no repositório que já contém `supabase/migrations` (`sislacprivado`). O `sislac-api` não manterá cópia concorrente dessas migrations.

Migrations centrais/tenant e migrations de cache sem consumidor serão removidas do backend.

## 6. Autenticação e autorização

### 6.1 Autenticação

Manter a integração mínima já existente com Supabase Auth:

1. receber `Authorization: Bearer <token>`;
2. validar server-side no Supabase Auth usando `SUPABASE_URL` + publishable key;
3. obter UUID do usuário autenticado;
4. falhar fechado em token inválido/expirado ou indisponibilidade relevante;
5. nunca registrar o token em log.

Não haverá login clínico Laravel, senha duplicada ou Sanctum como segunda identidade se nenhum consumidor real permanecer.

### 6.2 Autorização

Remover `memberships`, `TenantPermission` e `MembershipAuthorizer` ligados ao banco central.

Criar uma autorização simples baseada na fonte atual do Supabase. O middleware `permission:<nome>` consulta a permissão canônica do usuário no próprio Supabase — preferencialmente reaproveitando `has_permission(user_uuid, permission)` — e mantém o mesmo vocabulário do frontend/RLS.

A autorização nunca será derivada de `user_metadata` editável pelo usuário.

## 7. Rotas e domínio

As rotas clínicas permanecem em `routes/api.php`.

Remover middleware `tenant` e o prefixo conceitual de tenancy. Exemplo:

```php
Route::middleware(['supabase.auth', 'permission:visualizar_pacientes'])
    ->get('/pacientes', ListPacientesController::class);
```

Os módulos `Pacientes`, `Atendimentos`, `Rotina` e `Financeiro` permanecem apenas se suas Actions/Queries/Requests/Resources tiverem consumidor real e estiverem em concordância com o schema atual do Supabase.

Referências explícitas a `DB::connection('tenant')` serão substituídas pela conexão padrão da aplicação ou removidas quando forem código sem consumidor.

Não criar Repository/DTO/CQRS adicional apenas por arquitetura. Abstrações novas só entram quando eliminarem duplicação real ou isolarem uma integração externa concreta.

## 8. Super Admin

O Super Admin Laravel atual existe para administrar `tenants` e provisioning físico. Esse plano será removido junto com sua necessidade:

- controllers administrativos de tenants;
- views Blade administrativas;
- login local de Super Admin;
- `RequireSuperAdmin` ligado ao banco central;
- comando de bootstrap do Super Admin local;
- models/factories/migrations/testes exclusivamente desse plano.

O papel `super_admin` existente no Supabase não é removido. Caso uma interface administrativa seja necessária no futuro, ela deve usar a mesma identidade Supabase e somente funcionalidades concretas — sem recriar banco central preventivamente.

## 9. Cache, sessão, fila e armazenamento

- remover migration/tabela de cache do Laravel;
- usar cache `file` em produção enquanto não houver requisito de cache distribuído;
- manter fila `sync` enquanto não houver job real;
- não adicionar Redis, Horizon ou worker;
- não usar sessão para autenticação clínica; sessão web só permanece se houver consumidor real após a remoção do Admin Blade;
- Supabase Storage continua sendo armazenamento de objetos do produto; `local` fica apenas para temporários/logs necessários ao Laravel.

## 10. Dependências Composer

Após varredura de referências, remover dependências sem consumidor. Alvos explícitos:

- remover `stancl/tenancy`;
- remover `laravel/sanctum` se nenhum uso real restar após retirar o plano central;
- remover ferramentas dev (`laravel/boost`, `laravel/pail`, `laravel/pao`) se não houver script/configuração/fluxo realmente usado;
- manter apenas framework, ferramentas de qualidade e testes que tenham consumidor real.

Nunca executar `composer update` amplo. Alterações do lockfile devem ser consequência apenas da remoção explícita de pacotes e serão validadas.

## 11. Docker e deploy

O Docker deixa de ser requisito do projeto.

Se nenhum consumidor residual depender dele, remover:

- `docker-compose.yml`;
- Dockerfiles/scripts de bootstrap PostgreSQL;
- pgAdmin e documentação correlata.

Deploy alvo simples no KVM 2:

```text
Ubuntu 24.04
Nginx
PHP 8.4 + PHP-FPM
Composer
Git
Laravel
  -> Supabase PostgreSQL/Auth/Storage
```

Sem PostgreSQL local, sem pgAdmin e sem database-per-lab na VPS.

## 12. Testes

A suíte será reduzida para provar comportamento que o Laravel realmente possui.

### Manter/refatorar

- autenticação Bearer Supabase;
- autorização por permissões atuais do Supabase;
- validação de Requests;
- regras de Pacientes/Atendimentos/Rotina/Financeiro que continuam executadas no Laravel;
- idempotência, rollback e invariantes que sejam responsabilidade do backend;
- health check;
- segurança: token inválido, permissão ausente, payload controlado pelo servidor;
- testes de integração PostgreSQL somente onde o Laravel realmente depende de comportamento PostgreSQL.

### Remover

- criação/remoção de banco tenant;
- isolamento A/B de bancos físicos;
- central schema/memberships/tenants;
- provisioning;
- Super Admin local;
- contratos cujo único objetivo era comparar uma cópia Laravel com a origem Supabase;
- testes de migrations que o Laravel deixa de possuir;
- guards de arquitetura antiga.

O CI continua executando Composer Validate/Audit, Pint, Larastan nível 8 e Pest. PostgreSQL 17 no CI pode continuar somente como banco de integração único quando testes realmente precisarem dele — nunca como simulação de database-per-lab.

## 13. Limpeza de repositório

A implementação deve realizar varredura de referência antes de apagar e, ao final, varredura de órfãos.

Categorias obrigatórias:

- arquivos mortos;
- classes sem referência;
- imports sem uso;
- rotas sem consumidor;
- configs sem consumidor;
- env vars obsoletas;
- migrations obsoletas;
- scripts/guards antigos;
- testes de arquitetura removida;
- docs antigas que afirmem database-per-lab como arquitetura vigente;
- Docker/pgAdmin se não usados;
- diretórios vazios;
- caches/artefatos gerados acidentalmente no repositório.

`vendor/`, `.env`, caches locais e artefatos de runtime nunca serão versionados.

## 14. Documentação final

Atualizar `README.md`, `.env.example`, `docs/DEPLOY.md` e `docs/SEGURANCA.md` para refletir uma única arquitetura.

Documentos históricos de Superpowers podem permanecer como histórico somente se estiverem claramente sob `docs/superpowers/` e não forem usados como documentação operacional. Documentação operacional não pode contradizer o runtime novo.

## 15. Estratégia de implementação

1. adicionar testes/guards RED que definem a nova fundação: sem `stancl`, sem conexões `central/tenant`, sem `X-Tenant`, sem provisioning;
2. simplificar Composer/config/database/bootstrap/routes;
3. implementar contexto Supabase Auth + PostgreSQL/RLS da request;
4. substituir autorização central por permissões do Supabase;
5. adaptar Pacientes e provar o fluxo fim a fim;
6. adaptar Atendimentos, Rotina e Financeiro sem recriar abstrações antigas;
7. remover Admin/Platform/Tenancy/Provisioning/migrations/testes mortos;
8. remover Docker/pgAdmin e scripts/guards obsoletos;
9. atualizar documentação e `.env.example`;
10. executar varredura final de referências e órfãos;
11. executar todos os gates de qualidade no mesmo SHA;
12. revisar diff completo antes do PR.

## 16. Critérios de aceite

A simplificação só estará concluída quando, no mesmo SHA:

- não houver `stancl/tenancy` nem runtime database-per-lab;
- não houver `sislac_central`, `central.users`, `tenants`, `memberships`, `TenantProvisioner` ou `X-Tenant` no runtime;
- não houver `DB_ROOT_*` ou `TENANT_DB_*` no `.env.example`;
- Laravel usar uma única fonte de identidade clínica: Supabase Auth;
- Laravel usar o PostgreSQL do Supabase como fonte de verdade;
- autorização usar permissões atuais do Supabase, não uma cópia central;
- o contexto RLS for transacional e não puder vazar entre requests;
- Pacientes, Atendimentos, Rotina e Financeiro mantidos tiverem testes verdes;
- nenhuma migration Laravel tentar recriar o schema clínico do Supabase;
- nenhum código/admin/teste/script/doc operacional depender da arquitetura removida;
- cache de banco, pgAdmin e Docker forem removidos se não houver consumidor real;
- Composer Validate/Audit, Pint, Larastan nível 8 e Pest passarem;
- busca por símbolos legados e diretórios vazios não apontar resíduos funcionais;
- `git status` do branch de trabalho estiver limpo após os commits finais;
- deploy puder ser feito em Ubuntu + Nginx + PHP-FPM + Laravel, sem PostgreSQL local.

## 17. Não objetivos

Esta fase não irá:

- migrar o banco para fora do Supabase;
- trocar Supabase Auth;
- substituir Supabase Storage;
- adicionar Redis/Horizon/Reverb;
- criar novo SaaS multi-tenant;
- criar billing/planos/assinaturas preventivamente;
- adicionar microserviços ou CQRS;
- alterar funcionalidades clínicas que não sejam necessárias para a simplificação e concordância com o Supabase atual.

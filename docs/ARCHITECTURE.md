# Arquitetura — SISLAC API

A especificação normativa desta migração é `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`, complementada pela Fase 0 aprovada em `docs/superpowers/specs/2026-09-08-fase-0-saneamento-fundacao-design.md`. Documentação oficial Laravel 13, PostgreSQL, Supabase e `stancl/tenancy` prevalece sobre comportamento legado.

## Objetivo

O Laravel é o backend definitivo do SISLAC. O frontend React/Vite existente permanece durante a migração progressiva. O Supabase atual continua funcionando como baseline de produção, fonte de autenticação clínica transitória e origem de leitura/concordância até que cada onda tenha equivalência comprovada e o consumidor correspondente seja migrado.

A arquitetura final é **database-per-lab**:

```text
Frontend existente
  | Bearer Supabase
  v
Laravel
  |-- valida identidade no Supabase Auth
  |-- PostgreSQL central: users / tenants / memberships / provisionamento
  |-- PostgreSQL físico do laboratório: domínio clínico
  `-- Supabase PostgreSQL read-only: concordância/migração
```

## Bancos

| Conexão | Finalidade |
|---|---|
| `central` | usuários correlacionados, tenants, memberships, Super Admin, provisionamento e auditoria de plataforma |
| `tenant_template` | molde PostgreSQL usado pelo `stancl/tenancy` |
| `tenant` | conexão dinâmica ao banco físico do laboratório durante a requisição |
| `supabase_source` | leitura/concordância do Supabase durante a transição; nunca conexão default |

Cada novo laboratório recebe um banco PostgreSQL físico dedicado. O nome do banco é gerado/controlado pelo backend; o navegador não escolhe identificador físico arbitrário.

## Identidade e tenancy

Durante a transição existem dois contextos explicitamente separados:

- **usuário clínico:** autentica no Supabase Auth; a API recebe Bearer token, valida server-side em `/auth/v1/user`, exige que o UUID já exista em `central.users` e só então aplica memberships/permissões Laravel;
- **Super Admin:** autenticação web Laravel própria, restrita ao plano central.

Token válido não cria automaticamente usuário, membership, tenant ou permissão. Dados de autorização não são aceitos de `user_metadata` do token.

Depois da identidade clínica validada:

1. memberships ativas determinam os laboratórios permitidos;
2. um laboratório autorizado é selecionado;
3. middleware inicializa o contexto tenant;
4. o domínio opera no banco dedicado;
5. o contexto tenant é encerrado deterministicamente, inclusive em exceções.

`X-Tenant` nunca concede acesso por si só; apenas seleciona um vínculo já autorizado.

`stancl/tenancy` permanece pequeno: somente `DatabaseTenancyBootstrapper` é necessário. Não adicionar bootstrappers de cache, filesystem ou queue sem consumidor real.

## Provisionamento de novo laboratório

O provisionamento existente é a única trilha de criação de um novo banco:

```text
registro central com status provisioning
  -> advisory lock
  -> CREATE DATABASE
  -> inicializar tenancy
  -> executar database/migrations/tenant
  -> smoke check do banco correto
  -> status active
```

Falha não ativa laboratório parcial. O usuário HTTP normal não recebe privilégios administrativos; a credencial de provisionamento é separada.

## Super Admin

O Super Admin é Laravel e server-rendered. Ele usa somente o plano central para visualizar laboratórios/status, iniciar provisionamento e executar operações globais que realmente pertencem à plataforma. Não criar outro SPA ou pacote administrativo preventivo.

## Supabase durante a transição

O Supabase não é recriado dentro do banco central. Ele possui dois papéis transitórios:

1. **Auth clínico:** validação server-side do Bearer token atual;
2. **origem de concordância:** leitura PostgreSQL para validar/migrar módulos.

A conexão `supabase_source` possui defesa em profundidade:

- nunca é a conexão default;
- `SupabaseSource` executa `SET default_transaction_read_only = on`;
- o projeto Supabase atual possui `supabase_read_only_user` com `default_transaction_read_only=on`, SELECT em `public.pacientes` e sem INSERT/UPDATE/DELETE/CREATE DATABASE; essa é a credencial recomendada para `SUPABASE_DB_USERNAME`;
- o gate live falha se a sessão não estiver read-only.

Nenhuma tabela, RPC, Edge Function, policy ou dado do Supabase é removido apenas porque uma implementação Laravel surgiu.

## Banco central mínimo

Novas instalações criam somente o estado de plataforma necessário hoje:

- `users`;
- `tenants`;
- `memberships`;
- `provisioning_runs`;
- `platform_audit`;
- tabelas efetivamente necessárias a cache/sessão do Laravel.

`plans` e `subscriptions` foram removidos da baseline por não possuírem consumidor runtime. Em banco central já existente, `platform:audit-unused-tables` apenas informa presença/contagem; não executa `DROP`.

Dados clínicos permanecem nos bancos tenant, nunca duplicados no central.

## Domínio do laboratório

`app/Domain` contém as regras que operam no banco dedicado do laboratório. Pacientes é a primeira onda concluída. Atendimentos está implementado no PR #7 sobre a fundação da Fase 0 e permanece isolado do `main` e do cutover do frontend até que os gates e dependências da onda sejam concluídos.

### Atendimentos

O agregado de Atendimentos vive somente no banco tenant e é composto por `atendimentos`, `atendimento_exames`, `atendimento_pagamentos` e `atendimento_audit`. Protocolo, idempotência, campos derivados e auditoria são protegidos no PostgreSQL; criação e edição são transacionais.

O fluxo HTTP clínico é sempre:

```text
Bearer Supabase
  -> supabase.auth
  -> membership/tenant autorizado
  -> tenant.permission ou autorização específica do PATCH
  -> domínio no banco físico do laboratório
```

Não existe autenticação clínica Laravel/Sanctum para esse módulo. Não existe `DELETE /api/atendimentos`; cancelamento é evento de negócio auditado. A disponibilidade do backend não autoriza o cutover do React/Vite: Rotina e Financeiro/Convênios/Caixa ainda precisam cobrir as invariantes dependentes antes da troca do consumidor.

## Fronteira Platform ↔ Domain

O CI impede:

- `app/Domain` acessar explicitamente o banco central ou importar `App\Platform`;
- `app/Platform` importar domínio clínico;
- uma segunda estratégia de tenancy;
- infraestrutura futura sem consumidor real.

A camada HTTP é a coordenadora entre identidade central e inicialização tenant.

## Infraestrutura

PostgreSQL é obrigatório. Cache/sessão persistentes existem porque têm consumidor atual. A fila permanece `sync`, sem migration `jobs`, worker, Redis ou Horizon enquanto nenhum fluxo runtime exigir execução assíncrona.

Redis, Reverb, cache distribuído, workers, WebSockets ou outros serviços só entram na onda que apresentar consumidor e testes concretos.

## Conformidade em duas camadas

1. **Manifesto offline:** fixa a evidência versionada e valida formato/hash/invariantes no CI, sem acesso a produção.
2. **Live:** `php artisan contract:supabase-live` consulta exclusivamente em modo read-only os módulos já migrados e detecta drift real.

O termo “conforme” só é usado para um módulo após o gate live correspondente. Um manifesto íntegro sozinho não significa conformidade atual.

## Estratégia por ondas

1. fixar contrato executável atual do módulo;
2. escrever testes;
3. implementar schema/regra Laravel mínima;
4. executar conformidade live e testes de autorização/integridade;
5. adaptar o consumidor;
6. cortar a origem anterior somente depois da prova de equivalência.

Não avançar uma nova onda com a anterior incompleta ou com CI vermelho.

## Gates

Toda mudança deve passar no mesmo SHA:

- `composer validate --strict`;
- `composer audit --locked`;
- Pint;
- Larastan nível 8;
- Pest em PostgreSQL real;
- integridade offline do manifesto;
- guards de fronteira, banco, arquivos, `.env` e escopo arquitetural;
- migrations centrais e tenant reproduzíveis;
- provisionamento/smoke test quando a mudança tocar tenancy.

Antes de declarar uma onda conforme, executar também o gate live em ambiente confiável com credencial PostgreSQL read-only.

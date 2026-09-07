# Arquitetura — SISLAC API

A referência de implementação desta migração é
`docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`.
Documentação oficial Laravel 13, PostgreSQL, Supabase e, quando aplicável,
`stancl/tenancy` prevalece sobre comportamento legado.

## Em uma frase

Um único deploy Laravel atende muitos laboratórios. O plano central mantém
identidade, vínculos e provisionamento; cada laboratório possui seu próprio
banco PostgreSQL. Não existe subdomínio por laboratório.

## Fluxo de uma requisição autenticada

1. O SPA first-party autentica com Laravel Sanctum em modo stateful, usando
   sessão/cookie e CSRF.
2. O usuário autenticado pertence ao banco `central`.
3. `memberships` determina a lista de laboratórios ativos que o usuário pode
   acessar.
4. Com um único vínculo ativo, o laboratório é selecionado automaticamente.
5. Com vários vínculos ativos, `X-Tenant` seleciona um deles; o cabeçalho nunca
   autoriza por si só.
6. Somente após a autorização central o middleware inicializa a conexão do
   banco daquele laboratório.
7. O contexto tenant é encerrado deterministicamente ao fim da requisição,
   inclusive quando uma exceção é lançada.

Bearer tokens permanecem disponíveis apenas para integrações, clientes
externos ou automações que realmente necessitem de API tokens.

## Bancos e conexões

| Conexão | Finalidade |
|---|---|
| `central` | usuários, tenants, memberships, planos, assinaturas e provisionamento |
| `tenant_template` | configuração-base do PostgreSQL usada pelo `stancl/tenancy` |
| `tenant` | conexão dinâmica criada e encerrada pelo `stancl/tenancy` em runtime |
| `sqlite` | somente testes locais rápidos; não faz parte do contrato de produção |

O nome `tenant` é reservado à conexão dinâmica gerenciada pelo tenancy package;
código de negócio não a cria, não a reconfigura e não seleciona banco por conta
própria.

PostgreSQL é o único banco de produção. MySQL, MariaDB e SQL Server não fazem
parte do contrato do SISLAC.

## Plano central

`app/Platform` contém código que só conhece o banco central. A primeira fundação
usa UUID para `users` e `tenants`, preservando interoperabilidade com as
identidades atuais do Supabase.

Tabelas centrais:

- `users`;
- `tenants`;
- `memberships`;
- `plans`;
- `subscriptions`;
- `provisioning_runs`;
- `platform_audit`.

Invariantes estruturais ficam preferencialmente no PostgreSQL: FKs, uniques e
índices explícitos. Regras de negócio que não são invariantes estruturais ficam
em classes pequenas, nomeadas pela intenção.

## Domínio do laboratório

`app/Domain` contém pacientes e receberá, por ondas, atendimentos, exames,
coleta, análise, resultados, financeiro e demais regras do laboratório. O
domínio não conhece `App\Platform` e não seleciona banco central.

A primeira onda migrada é **Pacientes**. O contrato versionado em
`docs/contracts/pacientes.json` fixa o frontend de referência, schema físico
observado no Supabase, permissões, serialização, filtros, paginação e
normalizações. A API Laravel disponibiliza leitura, criação e edição; exclusão
não integra esta onda porque o frontend fixado não possui consumidor executável
desse fluxo.

No banco tenant, `pacientes` preserva as invariantes relevantes por constraints
e índices PostgreSQL. A busca case-insensitive por nome possui suporte GIN com
`pg_trgm`, enquanto a paginação keyset possui índice composto por
`(updated_at,id)`. Os dois access paths são verificados separadamente com
`EXPLAIN (ANALYZE, BUFFERS)`: em uma consulta que combina filtro textual,
ordenação e `LIMIT`, o planner pode legitimamente preferir o índice de
paginação para satisfazer a ordenação e aplicar o predicado textual como
filtro. Os testes, portanto, comprovam a utilizabilidade de cada índice sem
forçar uma estratégia específica quando ambos competem no mesmo plano, nem
impõem números de latência artificiais ao runner de CI.

## Fronteira Platform ↔ Domain

O CI impede:

- `app/Domain` e controllers tenant importarem `App\Platform` ou acessarem
  explicitamente a conexão central;
- `app/Platform` importar `App\Domain` ou acessar a conexão tenant.

A camada HTTP de contexto é a única coordenadora entre identidade central e
inicialização tenant. Essa regra reduz o risco de vazamento entre laboratórios
e torna a arquitetura legível sem depender de convenções implícitas.

## Seleção de tenant

A política pura `TenantSelection` recebe apenas IDs de tenants provenientes de
memberships ativas e um ID solicitado opcional. Ela não conhece HTTP, facades,
conexões ou container.

Regras:

- zero vínculos ativos: acesso negado;
- um vínculo ativo sem seleção: usa o único tenant;
- seleção não pertencente aos vínculos: acesso negado;
- múltiplos vínculos sem seleção: exige seleção;
- múltiplos vínculos com seleção autorizada: usa o tenant escolhido.

A consulta `ActiveTenantMemberships` retorna somente IDs necessários, em uma
consulta ao banco central, sem carregar grafos Eloquent desnecessários.

## Provisionamento

O usuário HTTP `sislac_app` nunca recebe `SUPERUSER`, `CREATEDB` ou
`CREATEROLE`. O provisionamento roda fora do caminho normal de requisição e
segue uma máquina de estados auditável:

`provisioning → create database → migrations → seed mínimo → smoke check → active`.

Falha não produz tenant parcialmente ativo. Retry é idempotente e a criação do
banco usa advisory lock PostgreSQL para serializar provisionamentos do mesmo
tenant.

## Concordância com Supabase

`docs/contracts/supabase-baseline.json` fixa somente metadados da baseline:
SHA do frontend, PostgreSQL major, fingerprints dos artefatos geradores,
inventário estrutural, superfície consumida, storage, Edge Functions, realtime
e findings conhecidos. Não contém linhas clínicas nem credenciais.

Cada onda de domínio acrescenta um contrato específico. Para Pacientes,
`docs/contracts/pacientes.json` registra a tabela `public.pacientes`, RLS e
políticas observadas, índices relevantes, endpoints Laravel e diferenças
arquiteturais aprovadas. O Laravel não simula RLS entre laboratórios dentro de
uma mesma tabela: o isolamento primário é físico, um banco por laboratório, e
a autorização central ocorre antes da inicialização do banco tenant.

O gate `scripts/check-supabase-contract.php` valida integridade e contagens
determinísticas do manifesto global no CI. Mudança de superfície do frontend
exige atualização explícita da baseline antes de uma nova onda de migração.

Nenhum objeto é removido ou reescrito apenas porque aparenta estar sem uso. A
classificação de equivalência ocorre por onda de domínio, com evidência do
contrato executável do frontend/Supabase.

## Fases

| Fase | Escopo | Estado |
|---|---|---|
| 0 | Laravel, Docker, CI, docs e health check | concluída |
| 1A | PostgreSQL 17, plano central, UUID, constraints e seleção de tenant | concluída |
| 1B | Boost, Sanctum, stancl/tenancy, isolamento e provisionamento | concluída |
| 2A | primeira onda de domínio: Pacientes | em validação final |
| 2B | Atendimentos → Coleta → Análise → Resultados → Financeiro | pendente |
| 3 | adaptação progressiva do frontend para HTTP Laravel | pendente |
| 4 | migração de dados e corte do Supabase | pendente |
| 5 | PDF, WhatsApp oficial, integrações e serviços operacionais restantes | pendente |
| 6 | SaaS comercial | pendente |

## Gates de engenharia

Cada mudança deve permanecer compreensível sem conhecimento implícito da
implementação. O padrão é:

- TDD: RED → GREEN → REFACTOR;
- funções/classes pequenas e com responsabilidade única;
- nomes que expressem regra de negócio;
- nenhuma abstração sem consumidor real;
- Pint;
- Pest em PostgreSQL 17 real;
- Composer audit;
- contrato Supabase ↔ Laravel determinístico;
- Larastan nível 8 obrigatório;
- guards de fronteira e PostgreSQL-only;
- testes de segurança, isolamento, concorrência, provisionamento e performance.

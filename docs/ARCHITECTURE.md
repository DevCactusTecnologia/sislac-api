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
| `tenant` | molde temporário atual para banco de laboratório durante a Fase 1A |
| `sqlite` | somente testes locais rápidos; não faz parte do contrato de produção |

Na Fase 1B, ao instalar oficialmente `stancl/tenancy`, o molde atual será
renomeado para `tenant_template` antes da configuração do pacote. O nome
`tenant` ficará reservado à conexão dinâmica gerenciada pelo tenancy package.
Essa mudança será feita junto da instalação real das dependências e de seus
testes, nunca antecipadamente.

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

`app/Domain` conterá pacientes, atendimentos, exames, coleta, análise,
resultados, financeiro e demais regras do laboratório. O domínio não conhece
`App\Platform` e não seleciona banco central.

O schema tenant será migrado por ondas, sempre comparando o comportamento do
Laravel com o contrato executável do `sislacprivado`/Supabase antes do corte.

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

Falha não pode produzir tenant parcialmente ativo. Retry precisa ser
idempotente.

## Concordância com Supabase

`docs/conformance/supabase-runtime-baseline.json` registra somente metadados da
baseline: SHA do frontend, versão PostgreSQL, inventário estrutural e findings
de advisors. Não contém linhas clínicas nem credenciais.

Objetos do backend atual serão classificados somente com evidência em quatro
categorias:

- `required-runtime`;
- `required-compat`;
- `platform-specific`;
- `dead-or-legacy`.

Nenhum objeto é removido ou reescrito apenas porque aparenta estar sem uso.

## Fases

| Fase | Escopo | Estado |
|---|---|---|
| 0 | Laravel, Docker, CI, docs e health check | concluída |
| 1A | PostgreSQL 17, plano central, UUID, constraints e seleção de tenant | em validação final |
| 1B | Boost, Sanctum, stancl/tenancy, isolamento e provisionamento | próxima |
| 2 | PDF, WhatsApp oficial, integrações, Horizon/Reverb | pendente |
| 3 | endpoints de domínio e adaptação progressiva do frontend | pendente |
| 4 | migração de dados e corte do Supabase | pendente |
| 5 | SaaS comercial | pendente |

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
- Larastan nível 8 quando instalado legitimamente na Fase 1B;
- guards de fronteira e PostgreSQL-only;
- testes de segurança, isolamento, concorrência e performance conforme o fluxo
  correspondente for habilitado.

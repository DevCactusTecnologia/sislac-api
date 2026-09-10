# Fase 0 — Saneamento da Fundação Laravel

Data: 2026-09-08

## 1. Objetivo

Endurecer a fundação do `sislac-api` antes de novas ondas de domínio, eliminando falsa sensação de conformidade, dependências antecipadas e ambiguidade de identidade durante a migração do `sislacprivado`/Supabase para Laravel.

A Fase 0 não adiciona funcionalidade clínica. Ela reduz risco e complexidade antes da continuidade do PR #7 de Atendimentos.

## 2. Baseline observada

### Laravel

- Repositório: `DevCactusTecnologia/sislac-api`.
- `main`: `49fef3687d2dcada9c83665a9d8147d86ee0397e`.
- Laravel 13 / PHP 8.4 / PostgreSQL 17.
- Arquitetura aprovada: banco central + database-per-lab via `stancl/tenancy`.
- Pacientes é a primeira onda de domínio integrada à fundação.

### Frontend

- Repositório: `DevCactusTecnologia/sislacprivado`.
- `main` observada: `57cc9be96703a41b207d530088369da1cc23cd94`.
- A baseline atualmente fixada no `sislac-api` (`0760c6123f3062842eaff5f7304b6460c00c058d`) está 65 commits atrás da `main` observada.
- O frontend atual consolidou runtime single-tenant e continua usando Supabase Auth.

### Supabase

- Projeto de origem: `eramenhnqcbyctyiqwlm`.
- PostgreSQL 17.
- Runtime atual efetivamente single-tenant.
- Não existem `tenants`/`memberships` no schema de origem; a instalação usa `lab_config`, `profiles` e `user_roles`.
- A tabela `public.pacientes` foi conferida diretamente na Fase 0 e continua compatível com o contrato Laravel atual: 24 colunas, políticas de leitura/cadastro/edição e unicidade de CPF/friendly id coerentes com a onda Pacientes.

## 3. Decisões arquiteturais

### 3.1 Database-per-lab permanece

O Laravel definitivo continuará usando banco central para plataforma/identidade e um PostgreSQL físico por laboratório.

Isso é uma decisão da arquitetura final, não uma tentativa de copiar o runtime atual do Supabase. Portanto:

- não reintroduzir `tenant_id` no domínio apenas para parecer com o legado;
- não criar segunda estratégia de tenancy;
- `X-Tenant` continua sendo apenas seleção, nunca autorização;
- `memberships` continua sendo a fronteira de autorização central antes da inicialização do banco tenant.

### 3.2 PR #7 permanece congelado

O PR #7 (`feat: migra agregado de Atendimentos para Laravel`) não será mesclado nem ampliado durante a Fase 0.

Depois da integração da Fase 0 em `main`, o PR #7 deve incorporar a nova fundação e ser revalidado contra a baseline atual antes de qualquer aprovação.

## 4. Conformidade Supabase ↔ Laravel

### Problema atual

`scripts/check-supabase-contract.php` apenas valida estrutura/hash de `docs/contracts/supabase-baseline.json` e contagens fixas. Ele não consulta o Supabase nem verifica a `main` atual do `sislacprivado`.

Consequentemente, o nome atual do gate comunica uma garantia que ele não fornece.

### Abordagens consideradas

1. **Continuar apenas com manifesto congelado.** Simples, mas permite drift silencioso. Rejeitada.
2. **Consultar produção Supabase em todo CI/PR.** Detecta drift, mas coloca credenciais de produção no caminho de código não confiável e torna CI dependente de rede/produção. Rejeitada.
3. **Contrato em duas camadas.** CI determinístico valida o manifesto; uma verificação live explícita, executada apenas em ambiente confiável e com credencial read-only, compara os módulos migrados com Supabase real. **Selecionada.**

### Desenho selecionado

Separar semanticamente os gates:

- `contract:integrity`: offline/determinístico; valida formato, hash e invariantes do manifesto versionado;
- `contract:live`: leitura real do `supabase_source`; compara schema/policies/índices necessários somente dos módulos já migrados;
- toda onda de domínio deve registrar o SHA atual do `sislacprivado` usado como fonte de comportamento;
- o CI normal não recebe credencial de produção;
- a verificação live é obrigatória antes de declarar uma onda conforme ou pronta para cutover, mas roda apenas em ambiente confiável;
- mensagens, documentação e nomes dos jobs devem diferenciar "integridade do manifesto" de "conformidade live".

O primeiro adaptador live será Pacientes porque o módulo já existe no Laravel e possui contrato versionado.

## 5. Identidade e autenticação durante a transição

### Problema atual

O frontend autentica em Supabase Auth, enquanto o Laravel possui `users.password`, `Auth::attempt()` e Sanctum stateful. Não existe ainda uma ponte completa entre as duas identidades.

Dois sistemas de senha independentes para usuários clínicos não serão aceitos como arquitetura de transição.

### Abordagens consideradas

1. **Manter Supabase Auth e Laravel Auth independentes para usuários clínicos.** Rejeitada por duplicar identidade e gerar divergência operacional.
2. **Migrar senhas imediatamente para Laravel.** Rejeitada por acoplar a Fase 0 a detalhes internos de hash/recuperação e aumentar o risco do cutover.
3. **Supabase Auth como fonte de identidade dos usuários clínicos durante a transição, com ponte server-side para o usuário central Laravel.** **Selecionada.**

### Desenho selecionado

Enquanto o frontend ainda depender de Supabase:

- usuários clínicos autenticam somente no Supabase Auth;
- requests clínicos ao Laravel enviam `Authorization: Bearer <access_token>`;
- um middleware/guard Laravel mínimo valida o token diretamente no Supabase Auth via `GET /auth/v1/user`, usando somente a URL do projeto e a chave pública/publishable apropriada; não usa `service_role`;
- a Fase 0 não adiciona biblioteca JWT/JWKS nem implementa criptografia própria: a validação remota é deliberadamente temporária, simples e compatível tanto com projetos em chave simétrica quanto assimétrica;
- timeout de rede deve ser curto, erro de Auth deve falhar fechado e não haverá retry automático de autenticação;
- o UUID retornado pelo Supabase (`user.id`, correspondente ao `sub`) é a chave de correlação com `central.users.id`;
- o Laravel resolve apenas usuário central já provisionado; **não cria usuário, membership, papel ou permissão automaticamente a partir de um token válido**;
- usuário válido no Supabase mas ausente/inativo no plano central recebe negação sem inicializar tenant;
- `memberships`/permissões Laravel continuam sendo a fonte de autorização server-side;
- `user_metadata` editável pelo usuário nunca é fonte de autorização;
- o login API por senha/Sanctum deixa de ser requisito para usuários clínicos durante a transição e não será mantido como caminho paralelo de acesso;
- o painel Super Admin Laravel é um domínio administrativo separado e pode continuar usando autenticação local Laravel, pois não representa a identidade clínica migrada do Supabase.

O cutover final de autenticação dos usuários clínicos para Laravel será uma fase própria. Nessa fase serão definidos recuperação de senha, criação/ativação de credenciais Laravel e retirada definitiva da dependência do Supabase Auth.

## 6. `supabase_source` read-only de verdade

A proteção atual por `SET default_transaction_read_only = on` será mantida como defesa em profundidade, mas não será considerada suficiente sozinha.

Requisitos:

- usar credencial PostgreSQL dedicada de leitura para `SUPABASE_DB_USERNAME`;
- a role não deve possuir INSERT/UPDATE/DELETE/TRUNCATE/DDL sobre os objetos de origem usados pela migração;
- conceder apenas CONNECT/USAGE/SELECT necessários;
- nenhum `service_role`/secret key entra no frontend ou no repositório;
- o código deve falhar de forma explícita se a conexão de concordância não estiver read-only conforme o contrato;
- testes offline continuam sem produção; a prova live ocorre somente no gate confiável `contract:live`.

A criação/ajuste da role no Supabase será uma mudança operacional separada e revisável, não embutida silenciosamente em migrations Laravel.

## 7. Remoção de infraestrutura sem consumidor

Princípio: nenhuma infraestrutura preventiva sem consumidor runtime atual.

### Queue/jobs

Hoje não há jobs/dispatch/`ShouldQueue` em `app/`. Portanto:

- remover a migration de jobs da fundação se ainda não houver estado produtivo que precise ser preservado;
- mudar a configuração padrão de fila para modo síncrono até surgir um consumidor real;
- não adicionar Redis/Horizon/worker.

### Plans/subscriptions

`plans` e `subscriptions` não possuem consumidor runtime atual. Serão removidos da fundação nesta fase, salvo se a inspeção antes da alteração encontrar dependência executável real não observada na revisão.

Quando houver regra concreta de assinatura/plano, voltam como uma mudança pequena e testada.

### Regra de segurança de banco já inicializado

Antes de apagar migrations ou objetos, verificar se existe banco central persistente em uso. Se existir, nenhuma tabela será apagada apenas para "limpeza" sem evidência de que está vazia/sem consumidor; a remoção física será tratada com migration de cleanup única e explícita. Se a fundação ainda não possui estado persistente relevante, preferir corrigir a baseline em vez de acumular migration corretiva.

## 8. Repositório e governança

O repositório `DevCactusTecnologia/sislac-api` está público apesar de documentado como código interno.

A Fase 0 deve torná-lo privado antes de ampliar o domínio clínico.

Isso é governança, não substituto para segurança de aplicação: nenhum segredo será versionado mesmo após tornar o repositório privado.

## 9. Alterações esperadas

Arquivos/camadas candidatas, sujeitas a confirmação por TDD:

- `docs/contracts/supabase-baseline.json`;
- `docs/contracts/pacientes.json`;
- `scripts/check-supabase-contract.php` (renomear/reduzir responsabilidade ou substituir por verificador de integridade);
- novo verificador live focado em contratos migrados;
- `tests/Contract/*`;
- `tests/Feature/Platform/SupabaseSourceTest.php`;
- middleware/serviço mínimo de identidade Supabase para requests clínicos;
- rotas/API auth atuais somente na medida necessária para eliminar o caminho clínico paralelo por senha;
- `.env.example` e configurações de serviços/database apenas onde necessário;
- `database/migrations/0001_01_01_000002_create_jobs_table.php`;
- migration central de fundação somente se a remoção segura de `plans/subscriptions` for comprovada;
- documentação de arquitetura/segurança/deploy;
- `.github/workflows/ci.yml` apenas para corrigir nomenclatura/semântica do gate offline; não adicionar workflow novo nem credencial live.

## 10. Não objetivos

A Fase 0 não irá:

- implementar Atendimentos;
- modificar o `sislacprivado`;
- escrever dados clínicos no Supabase;
- alterar o schema clínico do Supabase;
- criar Redis, Horizon, Reverb, filas ou event bus;
- criar DTOs/repositories/CQRS preventivos;
- alterar o modelo database-per-lab;
- fazer cutover final de autenticação;
- auto-provisionar usuário, tenant ou permissões a partir do Supabase;
- criar migrations corretivas sem primeiro verificar se a baseline pode ser corrigida de forma limpa.

## 11. Estratégia de implementação

Após aprovação desta especificação:

1. escrever testes RED para semântica correta dos gates, drift de baseline e identidade de transição;
2. atualizar manifesto/baselines para a `main` atual observada e separar integridade de conformidade live;
3. implementar prova live de Pacientes usando `supabase_source` read-only;
4. implementar o verificador remoto mínimo de identidade Supabase e resolução do usuário central, sem auto-provisionamento;
5. eliminar o caminho clínico paralelo por senha/Sanctum sem afetar o Super Admin Laravel;
6. endurecer contrato read-only da conexão;
7. remover infraestrutura sem consumidor após confirmação de ausência de dependências;
8. atualizar documentação e guards;
9. executar Composer Validate/Audit, Pint, Larastan nível 8, Pest e guards no mesmo SHA;
10. executar `contract:live` em ambiente confiável;
11. revisar diff completo e somente então abrir PR da Fase 0 para `main`.

## 12. Critérios de aceite

A Fase 0 só estará concluída quando houver evidência no mesmo SHA de que:

- CI não chama mais uma verificação estática de "conformidade Supabase ↔ Laravel";
- manifesto offline está atualizado e determinístico;
- Pacientes passa em conformidade live contra o Supabase real sem escrita;
- usuários clínicos têm uma única fonte de autenticação durante a transição: Supabase Auth;
- um token válido não cria privilégios automaticamente no Laravel;
- autorização clínica permanece exclusivamente server-side no plano central antes de inicializar tenant;
- o Super Admin Laravel continua funcionando de forma independente;
- `supabase_source` usa defesa em profundidade read-only;
- queue/jobs não permanecem sem consumidor;
- `plans/subscriptions` não permanecem preventivamente sem consumidor, salvo dependência executável comprovada;
- nenhuma nova infraestrutura futura foi adicionada;
- database-per-lab, Pacientes e isolamento central/tenant continuam sem regressão;
- PR #7 continua não mesclado e é revalidado apenas depois da Fase 0;
- repositório está privado;
- todos os gates estáticos, testes e análise de dependências passam.

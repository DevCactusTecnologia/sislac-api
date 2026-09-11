# Arquitetura — SISLAC API

## Objetivo

O SISLAC API é uma camada Laravel enxuta sobre o Supabase existente. O Laravel concentra HTTP, validação, autorização, regras de negócio, transações e integrações; o Supabase continua responsável por PostgreSQL, Auth e Storage.

```text
Frontend React / Vercel
        |
        | Authorization: Bearer <access_token>
        v
Laravel API
        |
        |-- supabase.auth: valida identidade
        |-- supabase.db: aplica contexto RLS em transação
        |-- permission:*: valida public.has_permission
        |-- Domain: executa regras de negócio
        v
Supabase PostgreSQL
```

Não existe banco de aplicação paralelo. A API usa uma única conexão PostgreSQL configurada em `config/database.php` e apontada para o projeto Supabase do ambiente.

## Autenticação

O frontend continua autenticando no Supabase Auth. A API recebe o access token do usuário e o valida server-side. O principal autenticado contém a identidade necessária ao request; não existe cópia local obrigatória do usuário para autenticar a requisição.

A publishable key identifica o projeto Supabase e não substitui o access token do usuário. Chaves administrativas não participam da autenticação clínica normal.

## Contexto PostgreSQL e RLS

Após a autenticação, `ApplySupabaseDatabaseContext` abre uma transação PostgreSQL para o request e aplica o contexto do usuário autenticado. O objetivo é fazer com que policies e funções do Supabase enxerguem a mesma identidade que enxergariam em uma chamada autenticada direta.

A conexão da API deve usar uma role dedicada, sem privilégios administrativos e sem `BYPASSRLS`. O contexto efetivo da requisição é reduzido a `authenticated` e às claims necessárias.

Respostas HTTP com erro e exceções fazem rollback; requisições bem-sucedidas fazem commit. Regras de domínio que exigem concorrência continuam usando locks PostgreSQL dentro dessa transação.

## Autorização

A autorização canônica é `public.has_permission(user_id, permission)`. O middleware `permission:<nome>` e autorizações específicas dos módulos reutilizam essa mesma fonte de verdade.

Permissões nunca são aceitas de campos controláveis pelo cliente, cabeçalhos arbitrários ou metadata não confiável.

## Domínio

`app/Domain` contém regras de negócio sem uma camada genérica de abstração sem consumidor. Os módulos atualmente implementados são:

- Pacientes;
- Atendimentos;
- Rotina / Fluxo Operacional;
- Financeiro de pacientes;
- Caixa operacional;
- Saídas / despesas.

As invariantes já existentes no PostgreSQL continuam pertencendo ao banco. O Laravel não duplica cálculo ou validação crítica quando a função/trigger canônica é a autoridade.

## Atendimentos

Atendimentos operam sobre as tabelas do Supabase e mantêm protocolo, idempotência, valores derivados e auditoria. Criação e edição são transacionais. Cancelamento é uma transição de negócio; não há exclusão física do atendimento como atalho operacional.

Rotas de leitura usam `visualizar_atendimentos`; criação usa `criar_atendimento`. Atualizações avaliam a intenção real do payload para exigir as permissões correspondentes.

## Rotina / Fluxo Operacional

A Rotina opera diretamente sobre os exames do atendimento e preserva a mesma fonte de verdade. As filas de coleta/análise são consultas derivadas; não existe infraestrutura paralela de fila.

Os modos `completo`, `coleta_resultado` e `apenas_resultado` continuam suportados. Transições clínicas usam locks e auditoria e não mantêm uma segunda máquina de estados concorrente ao PostgreSQL.

## Financeiro

O financeiro permanece derivado dos atendimentos e pagamentos persistidos no Supabase. Pagamentos e estornos preservam histórico, caixa aplica as invariantes do banco e saídas/despesas usam reversão em vez de exclusão física como mecanismo contábil.

Cálculos canônicos já protegidos por funções/triggers PostgreSQL não são reimplementados em PHP.

## Cache, sessão e filas

Enquanto não houver consumidor real:

- cache: arquivo local;
- sessão: arquivo local;
- fila: `sync`;
- sem Redis, Horizon, Reverb ou workers persistentes.

Esses componentes só devem ser introduzidos quando uma necessidade concreta justificar custo operacional adicional.

## Testes

A suíte usa PostgreSQL 17 descartável e `tests/Fixtures/supabase-test-schema.sql`. O fixture reproduz somente o contrato necessário para testar RLS/contexto, permissões e os módulos implementados; não tenta clonar toda a plataforma Supabase.

Os testes automatizados nunca devem apontar para produção.

## Limites arquiteturais

1. Supabase é a única fonte de dados da aplicação.
2. Supabase Auth é a única identidade clínica.
3. Storage continua no Supabase.
4. Laravel não provisiona infraestrutura de banco por cliente.
5. RLS permanece ativa e relevante para operações do usuário.
6. Chaves/roles administrativas não são credenciais normais da API.
7. Não criar abstrações, serviços ou infraestrutura sem consumidor real.
8. Mudanças devem manter Pint, Larastan, Pest, audit e guards verdes no mesmo SHA.

A especificação aprovada desta simplificação está em `docs/superpowers/specs/2026-09-10-supabase-backend-simplification-design.md` e o plano de execução em `docs/superpowers/plans/2026-09-10-supabase-backend-simplification.md`.

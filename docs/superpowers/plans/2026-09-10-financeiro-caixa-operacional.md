# Financeiro — Caixa Operacional Implementation Plan

**Goal:** Portar para Laravel/PostgreSQL o Caixa Operacional aprovado do SISLAC, preservando o modelo simples de uma sessão aberta por unidade e contabilizando somente Dinheiro e PIX presencial vinculados automaticamente.

**Architecture:** O banco físico do laboratório é a fronteira de tenancy, portanto `caixa_sessoes` não recebe `tenant_id`. A unicidade da sessão aberta é garantida por índice único parcial em `unidade_id WHERE status = 'aberta'`. Abertura e fechamento são comandos Laravel transacionais; o fechamento bloqueia a sessão com `FOR UPDATE` e calcula o saldo exclusivamente no banco tenant. Triggers fazem a vinculação automática e impedem movimentos em sessão fechada. O Supabase live é baseline read-only.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, stancl/tenancy, Pest.

## Regras vinculantes

- Uma única sessão `aberta` por `unidade_id`.
- Sem caixa por operador e sem múltiplos turnos paralelos na mesma unidade.
- Somente recebimentos `Dinheiro` e `PIX` entram automaticamente no Caixa.
- Cartão, transferência e outras formas ficam fora.
- O pagamento usa a `unidade_id` do atendimento para localizar a sessão aberta.
- O pagamento continua válido quando não existe sessão aberta; apenas fica sem `caixa_sessao_id`.
- Sessão fechada não aceita novos movimentos.
- Fechamento é server-side: `saldo_final = valor_abertura + dinheiro + pix - saidas`.
- Pagamento estornado não entra no fechamento.
- Saída só entra quando `foi_pago = true`, está vinculada à sessão e não possui estorno de origem `saida`.
- `gestao_financeira` opera abertura/fechamento.
- Leitura do Caixa usa `visualizar_financeiro`.
- `valor_abertura` não pode ser negativo.
- Nenhum dado financeiro clínico usa a conexão `central`.
- Nenhuma escrita será feita no Supabase live.

## Dependência `financeiro_saidas`

O Laravel ainda não possui `financeiro_saidas`, embora o fechamento canônico dependa dela. Esta fase cria somente a estrutura de dados necessária ao Caixa, sem endpoint CRUD de despesas.

A estrutura preserva os campos live necessários e o vínculo `caixa_sessao_id`. Protocolo, assinatura e demais campos de compatibilidade são mantidos no schema, mas geração operacional/CRUD pertencem à subfase própria de Saídas.

Como o domínio `unidades` ainda não foi migrado para este backend, `caixa_sessoes.unidade_id` permanece `text` sem FK nesta fase. A autoridade continua sendo o snapshot `atendimentos.unidade_id` já existente.

## Task 1 — RED de contrato

Criar testes que provem:

- somente uma sessão aberta por unidade;
- unidades diferentes podem ter sessões abertas simultaneamente;
- abertura negativa é recusada;
- perfil sem `gestao_financeira` não abre/fecha Caixa;
- `Dinheiro` e `PIX` são vinculados automaticamente à sessão da unidade;
- outras formas não são vinculadas;
- sessão fechada rejeita vínculo explícito de movimento;
- fechamento soma Dinheiro/PIX efetivos, ignora pagamento estornado e subtrai Saídas pagas não estornadas;
- segundo fechamento é conflito e não altera histórico;
- `updated_at` acompanha alteração da sessão;
- schema de provisionamento chega à migration do Caixa.

Executar CI antes da produção e registrar o RED apenas quando as falhas forem comportamentais esperadas.

## Task 2 — Schema tenant

Criar migration `2026_09_10_000700_add_caixa_operacional.php`:

- `caixa_sessoes`;
- índice `uq_caixa_sessao_aberta_por_unidade` parcial;
- `financeiro_saidas` como dependência estrutural, sem API;
- FK de `atendimento_pagamentos.caixa_sessao_id` para `caixa_sessoes` com `ON DELETE SET NULL`;
- FK de `financeiro_saidas.caixa_sessao_id` para `caixa_sessoes` com `ON DELETE SET NULL`;
- checks de status e de valor de abertura;
- trigger de `updated_at`;
- triggers de attach/guard para pagamentos e saídas;
- bloqueio físico de DELETE em `financeiro_saidas` orientando uso de estorno.

Funções de trigger novas usam `SECURITY INVOKER`, `SET search_path = ''` e referências schema-qualified.

## Task 3 — Domínio Laravel

Criar:

- `CaixaSessao` model;
- query para sessão aberta por unidade;
- action `OpenCaixa`;
- action `CloseCaixa`;
- requests de abertura/fechamento/leitura;
- controllers pequenos.

`CloseCaixa` executa dentro de `DB::transaction`, faz `lockForUpdate()` na sessão e persiste o mesmo instante de fechamento retornado no resumo.

## Task 4 — HTTP e RBAC

Adicionar permissão `visualizar_financeiro` ao enum e ao papel `financeiro`; `admin` continua com todas por regra geral.

Rotas:

- `GET /api/financeiro/caixa/aberto?unidade_id=...` → `visualizar_financeiro`;
- `POST /api/financeiro/caixa/abrir` → `gestao_financeira`;
- `POST /api/financeiro/caixa/{id}/fechar` → `gestao_financeira`.

Nenhuma rota de Saídas será criada nesta fase.

## Task 5 — Documentação e gates

- criar contrato JSON e documento da subfase;
- atualizar `ARCHITECTURE.md`;
- atualizar schema version do provisionamento;
- executar no mesmo SHA final: Composer Validate, Composer Audit, manifesto Supabase, Pint, Larastan nível 8, Pest e guards.

## Fora de escopo

- CRUD/UI de Saídas;
- sangria;
- suprimento;
- caixa por operador;
- múltiplos caixas na mesma unidade;
- conciliação bancária;
- centro de custo;
- DRE;
- ERP;
- Convênios/Faturas;
- frontend cutover;
- impressão do comprovante (consumidor frontend permanece em fase própria).

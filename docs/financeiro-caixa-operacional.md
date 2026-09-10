# Financeiro — Caixa Operacional

## Objetivo

Esta subfase porta para Laravel/PostgreSQL o Caixa Operacional mínimo do SISLAC sem transformar o Financeiro em ERP. O fluxo permanece simples: abrir a sessão da unidade, receber automaticamente os pagamentos presenciais elegíveis, fechar com cálculo server-side e preservar o histórico.

O banco físico do laboratório é a fronteira de tenancy. Por isso, `caixa_sessoes` e a dependência estrutural `financeiro_saidas` vivem somente no banco tenant e não repetem `tenant_id`.

## Sessão por unidade

Existe no máximo uma sessão com `status = 'aberta'` para cada `unidade_id`. A garantia não depende apenas da API: o PostgreSQL aplica o índice único parcial `uq_caixa_sessao_aberta_por_unidade`.

Unidades diferentes podem manter sessões abertas simultaneamente. Esta subfase não implementa caixa por operador nem múltiplos caixas paralelos na mesma unidade.

`valor_abertura` é validado como valor monetário não negativo. A abertura exige a permissão `gestao_financeira`.

Na abertura, o cliente pode informar somente o identificador da unidade, o valor de abertura e observações. `responsavel_id`, `status`, `aberta_em`, `fechada_em`, `valor_fechamento` e `fechado_por` pertencem ao servidor e são explicitamente rejeitados quando enviados pelo cliente. O usuário responsável, o estado inicial e o instante de abertura são definidos pelo backend.

## Recebimentos vinculados ao Caixa

Pagamentos de pacientes continuam pertencendo a `atendimento_pagamentos`. O Caixa não cria uma segunda tabela de recebimentos.

Quando um pagamento novo possui `tipo = 'Dinheiro'` ou `tipo = 'PIX'`, o PostgreSQL procura a sessão aberta da `unidade_id` do atendimento e, se existir, preenche `caixa_sessao_id` automaticamente.

Outras formas de pagamento não são vinculadas automaticamente ao Caixa. Se não existir sessão aberta, um pagamento em Dinheiro/PIX continua válido e é persistido com `caixa_sessao_id = NULL`; ausência de Caixa não invalida o recebimento clínico.

Uma sessão fechada não aceita novo movimento explicitamente vinculado. Os triggers de vínculo/guarda usam lock de leitura da sessão para coordenar a operação com o fechamento.

## Fechamento server-side

O cliente não fornece totais nem estado de fechamento. No endpoint de fechamento, somente `observacoes` é campo operacional aceito do cliente. `sessao_id`, `unidade_id`, `valor_abertura`, `valor_fechamento`, `entradas_dinheiro`, `entradas_pix`, `saidas`, `saldo_final`, `status`, `fechada_em` e `fechado_por` são explicitamente rejeitados se enviados no corpo da requisição.

`CloseCaixa` executa em transação, bloqueia a sessão com `FOR UPDATE` e calcula os valores diretamente no PostgreSQL tenant usando aritmética `numeric`:

```text
saldo_final = valor_abertura + dinheiro + pix - saidas
```

No cálculo:

- `dinheiro` considera pagamentos vinculados do tipo `Dinheiro` que não estejam estornados;
- `pix` considera pagamentos vinculados do tipo `PIX` que não estejam estornados;
- a autoridade canônica de estorno de pagamento é `financeiro_estornos` com `origem_tipo = 'pagamento'`;
- por compatibilidade de migração, `status_pagamento = 'estornado'` também é respeitado como defesa para histórico legado ainda não materializado no livro canônico;
- `saidas` considera somente Saídas pagas, vinculadas à sessão e sem estorno de origem `saida`.

O mesmo saldo calculado é persistido em `valor_fechamento`. Uma segunda tentativa de fechar a mesma sessão retorna conflito e não reescreve `fechada_em` nem o histórico já persistido.

## Histórico não destrutivo

Fechar uma sessão é uma atualização legítima de estado; portanto, `caixa_sessoes` não é append-only. Entretanto, `DELETE` físico de sessão é bloqueado pelo PostgreSQL para impedir desaparecimento do histórico financeiro.

`financeiro_saidas` também bloqueia `DELETE` físico e orienta correção por estorno. O livro `financeiro_estornos`, criado na subfase Financeiro Core, continua sendo a trilha canônica de correção financeira.

`updated_at` da sessão é mantido server-side por trigger.

## Dependência estrutural de Saídas

O fechamento canônico precisa descontar Saídas, mas o módulo operacional de despesas ainda não foi migrado. Por isso, esta subfase cria `financeiro_saidas` apenas como dependência estrutural compatível com o Caixa.

Não existe nesta fase endpoint HTTP, controller, action ou UI de CRUD de Saídas. A operação de despesas será tratada em subfase própria, sem ampliar antecipadamente o escopo do Caixa.

Como o domínio de `unidades` ainda não foi migrado para este backend, `unidade_id` permanece `text` sem FK nesta etapa. A unidade usada para vincular o pagamento é o snapshot já persistido em `atendimentos.unidade_id`.

## HTTP e autorização

As rotas do Caixa são:

```text
GET  /api/financeiro/caixa/aberto?unidade_id=...
POST /api/financeiro/caixa/abrir
POST /api/financeiro/caixa/{id}/fechar
```

A consulta exige `visualizar_financeiro`. Abertura e fechamento exigem `gestao_financeira`. O papel `financeiro` recebe essas permissões; `admin` continua autorizado pela regra global; `recepcionista` não recebe gestão do Caixa implicitamente.

## Integridade PostgreSQL

A migration `2026_09_10_000700_add_caixa_operacional.php` mantém as invariantes críticas no próprio banco:

- uma sessão aberta por unidade;
- valor de abertura não negativo;
- FK de `atendimento_pagamentos.caixa_sessao_id` para `caixa_sessoes`;
- vínculo automático de Dinheiro/PIX;
- rejeição de novo vínculo a sessão fechada;
- atualização server-side de `updated_at`;
- bloqueio de DELETE físico de sessão;
- bloqueio de DELETE físico de Saída.

As funções de trigger novas usam `SECURITY INVOKER`, `search_path = ''` e referências schema-qualified. Não existe `SECURITY DEFINER` nesta subfase.

## Concordância com o Supabase

O Supabase live foi usado somente em leitura para confirmar o schema e a semântica existentes de `caixa_sessoes`, `financeiro_saidas`, vínculos de `atendimento_pagamentos`, `financeiro_estornos` e regras históricas de abertura/fechamento.

Nenhuma escrita, DDL, migration ou alteração foi executada no Supabase live durante esta subfase. O Laravel preserva a intenção de negócio e endurece a fronteira HTTP e a integridade do histórico onde necessário.

## Testes de contrato

Os testes do Caixa provam em PostgreSQL tenant real e na validação HTTP:

- abertura e leitura por unidade;
- unicidade de sessão aberta no PostgreSQL;
- abertura negativa rejeitada;
- autorização de abertura e fechamento;
- campos de estado da abertura são server-side e rejeitados no request;
- vínculo automático apenas de Dinheiro/PIX;
- pagamento válido sem sessão aberta;
- rejeição de novo movimento em sessão fechada;
- fechamento calculado exclusivamente no servidor;
- campos derivados e de estado do fechamento são rejeitados no request;
- exclusão de pagamento com estorno canônico mesmo quando uma flag legada estiver dessincronizada;
- compatibilidade com pagamento legado marcado como estornado;
- desconto de Saídas pagas não estornadas;
- exclusão de Saída estornada;
- repetição de fechamento sem reescrita histórica;
- atualização de `updated_at`;
- bloqueio de DELETE físico de Saída e sessão.

O provisionamento registra `2026_09_10_000700_add_caixa_operacional` como schema tenant atual.

## Fora do escopo

CRUD/UI de Saídas, sangria, suprimento, caixa por operador, múltiplos caixas simultâneos na mesma unidade, conciliação bancária, centro de custo, DRE, ERP, Convênios/Faturas, resumo financeiro, cutover do frontend e impressão do comprovante permanecem em subfases próprias.

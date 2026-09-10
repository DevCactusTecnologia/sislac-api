# Financeiro — Saídas e Despesas

## Objetivo

Esta subfase transforma `financeiro_saidas`, criada anteriormente como dependência estrutural do Caixa, no módulo operacional mínimo de Saídas/Despesas do SISLAC. O escopo permanece financeiro e enxuto: criar, corrigir enquanto a Saída estiver aberta, efetivar pagamento, estornar sem apagar histórico e listar com filtros/paginação estável.

O PostgreSQL tenant continua sendo a autoridade. Não existe livro paralelo, fila, Redis, event bus, serviço externo ou duplicação de `tenant_id` dentro do banco físico do laboratório.

## Estados e correção

Os estados canônicos são:

```text
aberta -> paga
aberta -> cancelada (somente por estorno)
paga   -> cancelada (somente por estorno)
```

`paga` e `cancelada` são terminais para edição de campos de negócio. Uma Saída aberta pode permanecer aberta ou ser efetivada como paga. O PATCH bloqueia a linha com `FOR UPDATE` e recusa alterações quando o estado atual já é terminal.

O cliente não pode escrever `cancelada` diretamente em POST/PATCH. A transição para cancelada pertence exclusivamente ao endpoint formal de estorno.

## Criação

`POST /api/financeiro/saidas` exige `gestao_financeira` e aceita somente dados operacionais da despesa. `valor` precisa ser maior que zero e possuir no máximo duas casas decimais.

Os campos abaixo são sempre propriedade do servidor e precisam estar ausentes do payload:

- `id`;
- `protocolo`;
- `assinatura_protocolo`;
- `foi_pago`;
- `caixa_sessao_id`;
- `created_at`;
- `updated_at`.

O protocolo é gerado no PostgreSQL no formato `SAI-AAAA-NNNNNNN`. O estado default é `aberta`. Na criação `status = paga` é permitido; nesse caso o banco deriva `foi_pago = true` e preenche `data_pagamento` quando necessário.

`descricao`, `tipo_despesa`, `destino_pagamento` e `forma_pagamento` são normalizados com espaços internos consistentes antes da persistência.

## Edição e efetivação

`PATCH /api/financeiro/saidas/{id}` exige `gestao_financeira` e só opera se o estado persistido atual for `aberta`.

É permitido corrigir os campos editáveis da Saída aberta e efetivar `aberta -> paga`. O protocolo, a assinatura, o indicador derivado `foi_pago`, o vínculo de Caixa e timestamps continuam server-owned e não podem ser usados como porta lateral de mutação.

Saída já `paga` ou `cancelada` retorna conflito para tentativa de edição. A proteção existe em duas camadas: action Laravel e trigger PostgreSQL.

## Estorno não destrutivo

`POST /api/financeiro/saidas/{id}/estorno` exige `gestao_financeira` e um `motivo` não vazio. O motivo é normalizado antes da persistência.

O fluxo é transacional e segue esta ordem intencional:

```text
FOR UPDATE da saída
  -> confirmar ausência de estorno anterior
  -> INSERT em financeiro_estornos (origem_tipo = 'saida')
  -> UPDATE financeiro_saidas.status = 'cancelada'
```

O estorno é inserido antes da mudança de estado porque o trigger PostgreSQL recusa cancelamento sem o registro correspondente. Se qualquer etapa falhar, a transação inteira é revertida.

A linha original de `financeiro_saidas` não é apagada. `financeiro_estornos` continua append-only e a combinação de origem impede segundo estorno do mesmo lançamento. Uma nova tentativa retorna conflito sem reescrever histórico.

`DELETE` físico de Saída é bloqueado pelo PostgreSQL e não existe endpoint HTTP DELETE.

## Integração com Caixa

O campo `status`, e não um boolean enviado pelo navegador, determina se uma Saída é financeiramente paga. O PostgreSQL mantém `foi_pago` como derivação do estado.

Saída paga com `forma_pagamento = Dinheiro` ou `PIX` pode ser vinculada automaticamente à única sessão de Caixa aberta elegível. O vínculo continua server-side por `caixa_sessao_id`.

Quando uma Saída vinculada é estornada, o `caixa_sessao_id` histórico é preservado, mas a Saída cancelada deixa de compor o total de Saídas no fechamento. O Caixa desconta somente Saídas `paga` sem estorno de origem `saida`.

## Listagem canônica

`GET /api/financeiro/saidas` exige `visualizar_financeiro` e lê diretamente `financeiro_saidas` no banco tenant.

A consulta aceita:

- `search`: protocolo, descrição, tipo de despesa ou destino do pagamento;
- `status`: `aberta`, `paga` ou `cancelada`;
- `date_from` e `date_to`;
- `limit`, entre 1 e 100;
- cursor composto por `cursor_data` e `cursor_id`.

A ordenação é sempre:

```text
data DESC, id DESC
```

A paginação busca `limit + 1` e devolve `meta.nextCursor` somente quando existe página seguinte. O cursor composto mantém paginação determinística mesmo quando várias Saídas possuem exatamente a mesma data.

Valores monetários são serializados como strings com duas casas decimais.

## Integridade PostgreSQL

A migration `2026_09_10_000800_harden_financeiro_saidas.php` endurece as invariantes críticas:

- `valor > 0`;
- protocolo gerado pelo servidor e imutável;
- assinatura imutável depois de não nula;
- derivação de `foi_pago` e `data_pagamento` a partir do estado;
- vínculo automático elegível com Caixa;
- bloqueio de alteração dos campos de negócio em estados terminais;
- cancelamento somente quando já existe estorno de origem `saida`;
- manutenção server-side de `updated_at`;
- bloqueio de DELETE físico já estabelecido pelo Caixa.

As novas funções de trigger usam `SECURITY INVOKER`, `search_path = ''` e referências schema-qualified.

## HTTP e autorização

As rotas desta subfase são:

```text
GET   /api/financeiro/saidas
POST  /api/financeiro/saidas
PATCH /api/financeiro/saidas/{id}
POST  /api/financeiro/saidas/{id}/estorno
```

A leitura exige `visualizar_financeiro`. Criação, correção/efetivação e estorno exigem `gestao_financeira`. O papel `financeiro` recebe essas permissões pelas regras existentes; `recepcionista` não recebe gestão de despesas implicitamente.

## Concordância e divergência intencional

O Supabase live permaneceu origem de concordância em modo read-only. Nenhuma escrita, DDL ou migration foi executada nele nesta subfase.

`assinatura_protocolo` continua server-owned, porém o HMAC de protocolo não é introduzido aqui. A ausência deliberada dessa assinatura evita ampliar o escopo do CRUD de despesas com uma infraestrutura de assinatura ainda sem consumidor operacional dedicado. Essa diferença permanece explícita e não autoriza o cliente a preencher o campo.

## Testes de contrato

Os testes de Saídas exercitam PostgreSQL tenant real e cobrem:

- schema e invariantes de valor/protocolo/estado;
- criação aberta e paga;
- campos server-owned;
- normalização de texto;
- integração com Caixa para Dinheiro/PIX;
- correção de Saída aberta;
- efetivação `aberta -> paga`;
- bloqueio de edição terminal;
- estorno de Saída aberta e paga sem exclusão física;
- preservação do vínculo histórico com Caixa;
- motivo obrigatório e unicidade de estorno;
- fechamento de Caixa ignorando Saída cancelada;
- listagem ordenada e filtrada;
- busca textual nas quatro colunas definidas;
- paginação estável por `(data, id)`;
- limite máximo de 100 itens;
- autorização de leitura e gestão.

## Fora do escopo

Sangria, suprimento, centro de custo, conciliação bancária, DRE, ERP, Convênios/Faturas, resumo financeiro, cutover do frontend, impressão/UI de comprovante e assinatura HMAC de protocolo permanecem em ondas próprias.

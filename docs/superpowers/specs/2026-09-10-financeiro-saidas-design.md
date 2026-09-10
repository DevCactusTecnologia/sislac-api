# Financeiro — Saídas / Despesas — Design aprovado

## Objetivo

Migrar para o backend Laravel/PostgreSQL definitivo a operação de Saídas/Despesas do SISLAC, aproveitando a tabela `financeiro_saidas` já criada estruturalmente pela fase Caixa Operacional e substituindo o CRUD direto do frontend por comandos financeiros explícitos, não destrutivos e auditáveis.

A fase permanece enxuta: cadastrar, listar, corrigir Saída ainda aberta, efetivar pagamento e estornar. Não serão criados livro paralelo, DRE, centro de custo, conciliação bancária, contas a pagar completa, ERP, Redis, fila, worker ou novo mecanismo de Caixa.

## Base e fontes de verdade

- Backend base: `DevCactusTecnologia/sislac-api`, SHA `077c9a36893b896554e53fab7c04dd88f20a7799` (`fase-financeiro-caixa-operacional` concluída).
- Branch desta fase: `fase-financeiro-saidas`.
- Frontend de referência: `DevCactusTecnologia/sislacprivado`.
- Supabase live: baseline de concordância, somente leitura durante a migração.
- PostgreSQL tenant do Laravel: backend definitivo da funcionalidade.
- Nenhuma escrita, DDL ou migration será executada no Supabase live nesta fase.

O Supabase live consultado em 2026-09-10 possui `financeiro_saidas` com schema compatível com a estrutura já criada no Laravel e sem registros operacionais. A coluna dedicada `forma_pagamento` existe no live; portanto o backend novo não reproduz o legado `[pgto:X]` em `descricao`.

## Princípio

`financeiro_saidas` permanece a única fonte de verdade das despesas operacionais.

O frontend atual ainda opera Saídas como CRUD. O backend definitivo usa uma máquina de estados mínima:

```text
aberta -> paga -> cancelada
   \--------------> cancelada
```

- `aberta`: lançamento ainda não pago; dados operacionais podem ser corrigidos.
- `paga`: lançamento efetivado; dados financeiros tornam-se históricos e ficam congelados.
- `cancelada`: estado terminal produzido exclusivamente por estorno formal.

A correção de uma Saída paga é sempre feita por estorno formal e novo lançamento, preservando a linha original.

## Autoridade de estado

`status` é a autoridade canônica do estado financeiro.

`foi_pago` permanece por compatibilidade de schema, mas é derivado pelo backend/banco:

```text
status = 'aberta'    => foi_pago = false
status = 'paga'      => foi_pago = true
status = 'cancelada' => foi_pago = false
```

O cliente não pode enviar `foi_pago`.

`data_pagamento` segue o estado:

- `aberta`: `NULL`;
- `paga`: data explicitamente informada e validada ou `CURRENT_DATE` server-side quando ausente;
- `cancelada`: preserva a data histórica se a Saída já esteve paga.

Uma linha não pode receber `status='cancelada'` por POST/PATCH comum. A única transição para `cancelada` ocorre na ação de estorno, na mesma transação que cria `financeiro_estornos` com `origem_tipo='saida'`.

## Protocolo

O protocolo oficial é server-side e segue:

```text
SAI-AAAA-0000001
```

O ano deriva de `data`. A geração reutiliza `protocolo_sequence`, com incremento atômico por `(prefixo, ano)`. O cliente não envia protocolo oficial.

Depois da criação:

- `protocolo` é imutável;
- `assinatura_protocolo`, quando não nula, é imutável;
- esta fase não cria novo serviço HMAC, segredo ou infraestrutura genérica de assinatura;
- novos lançamentos Laravel podem manter `assinatura_protocolo = NULL` até a onda específica de assinatura verificável.

## Campos

Na criação são obrigatórios: `descricao`, `valor`, `tipo_despesa` e `destino_pagamento`. `valor` deve ser maior que zero e ter no máximo duas casas decimais.

São opcionais: `data`, `data_vencimento`, `forma_pagamento`, `status` (`aberta|paga`) e `data_pagamento` quando `status='paga'`. `data` usa `now()` quando ausente.

Campos controlados exclusivamente pelo servidor e proibidos como autoridade externa: `id`, `protocolo`, `assinatura_protocolo`, `foi_pago`, `caixa_sessao_id`, `created_at` e `updated_at`.

## Criação

`POST /api/financeiro/saidas`

- exige `gestao_financeira`;
- cria em transação tenant;
- default `status='aberta'`;
- pode nascer diretamente `paga` para preservar a simplicidade operacional do frontend atual;
- `status='cancelada'` é rejeitado;
- se nascer paga, normaliza `foi_pago=true` e `data_pagamento`;
- se nascer aberta, normaliza `foi_pago=false` e `data_pagamento=NULL`;
- Dinheiro/PIX pagos continuam sujeitos ao vínculo automático com o Caixa já implementado.

## Correção de Saída aberta

`PATCH /api/financeiro/saidas/{id}`

Somente Saída em `status='aberta'` pode ser corrigida. Campos editáveis enquanto aberta: `descricao`, `valor`, `tipo_despesa`, `destino_pagamento`, `data`, `data_vencimento` e `forma_pagamento`.

O mesmo PATCH pode efetivar `aberta -> paga`, permitindo informar `status='paga'`, `data_pagamento` e `forma_pagamento` na mesma operação.

Não é permitido pelo PATCH:

- alterar protocolo/assinatura;
- definir `foi_pago` ou `caixa_sessao_id`;
- definir `cancelada`;
- editar Saída já `paga`;
- editar Saída `cancelada`;
- reabrir estado terminal.

A ação bloqueia a linha com `FOR UPDATE` antes de validar e persistir.

## Saída paga

Após a transição para `paga`, valor, forma de pagamento, datas, descrição, classificação, destino, status e vínculo de Caixa tornam-se históricos. Erro posterior exige estorno formal e novo lançamento.

Isso impede que uma edição posterior altere retroativamente o fechamento do Caixa ou relatórios financeiros já consolidados.

## Estorno formal

`POST /api/financeiro/saidas/{id}/estorno`

Payload: `{ "motivo": "texto obrigatório" }`.

Regras:

- exige `gestao_financeira`;
- bloqueia a Saída com `FOR UPDATE`;
- motivo não vazio após normalização;
- recusa segundo estorno;
- aceita Saída `aberta` ou `paga`;
- cria `financeiro_estornos` com `origem_tipo='saida'`, `origem_id`, `motivo`, `valor` e `criado_por`;
- na mesma transação altera a Saída para `status='cancelada'` e `foi_pago=false`;
- preserva protocolo, valor, descrição, forma, datas e `caixa_sessao_id` como histórico;
- qualquer falha faz rollback integral;
- a UNIQUE existente em `(origem_tipo, origem_id)` permanece defesa física contra estorno duplicado.

Uma Saída estornada continua vinculada historicamente à sessão de Caixa, mas o fechamento a exclui por existir estorno canônico de origem `saida`.

## Proteções PostgreSQL

Uma nova migration endurece a tabela existente, sem recriá-la.

Invariantes obrigatórias:

- protocolo gerado automaticamente e imutável;
- assinatura imutável quando não nula;
- `valor > 0`;
- `status` limitado a `aberta|paga|cancelada`;
- coerência entre `status`, `foi_pago` e `data_pagamento`;
- criação direta como `cancelada` rejeitada;
- Saída paga não aceita reescrita de campos de negócio;
- Saída cancelada é terminal;
- transição para cancelada somente dentro do fluxo formal de estorno;
- DELETE físico continua bloqueado;
- vínculo com Caixa fechado continua bloqueado;
- `updated_at` server-side.

As funções novas/substituídas devem usar `SECURITY INVOKER`, `SET search_path=''` e referências schema-qualified. Não introduzir `SECURITY DEFINER`.

O trigger de vínculo de Saída ao Caixa deve se apoiar no estado canônico efetivo (`status='paga'`) e continuar vinculando apenas Dinheiro/PIX quando houver exatamente uma sessão aberta, evitando associação ambígua.

## Listagem

`GET /api/financeiro/saidas` exige `visualizar_financeiro` e é tenant/read-only.

Filtros mínimos: `search`, `status`, `date_from`, `date_to`, `limit` com teto server-side e cursor estável `(data,id)` em ordem decrescente.

A resposta expõe campos canônicos: `id`, `protocolo`, `data`, `descricao`, `valor`, `tipo_despesa`, `destino_pagamento`, `data_vencimento`, `status`, `foi_pago`, `data_pagamento`, `forma_pagamento`, `caixa_sessao_id`, `created_at`, `updated_at`.

O backend não reconstrói `cliente` a partir de `descricao` nem converte status em `Sim/Não`; isso é apresentação do frontend.

## API da fase

```text
GET   /api/financeiro/saidas
POST  /api/financeiro/saidas
PATCH /api/financeiro/saidas/{id}
POST  /api/financeiro/saidas/{id}/estorno
```

Não existe `DELETE /api/financeiro/saidas/{id}`.

## Autorização

- listar: `visualizar_financeiro`;
- criar: `gestao_financeira`;
- corrigir/efetivar: `gestao_financeira`;
- estornar: `gestao_financeira`.

O papel `financeiro` mantém essas permissões; `recepcionista` não recebe acesso adicional.

## Concorrência e erros

Criação de protocolo usa incremento atômico. PATCH e estorno usam transação + `FOR UPDATE`.

Dois comandos concorrentes sobre a mesma Saída são serializados pela linha. O comando que adquirir o lock depois deve reler o estado e respeitar a imutabilidade terminal.

Mapeamento HTTP: `404` inexistente; `409` conflito de estado/estorno duplicado; `422` payload inválido; `403` sem permissão.

Controllers permanecem finos. Violações físicas inesperadas do PostgreSQL não são mascaradas como sucesso.

## Testes obrigatórios

Testar por TDD, no mínimo:

- listagem vazia e filtros/cursor;
- criação aberta com protocolo server-side;
- criação paga com data de pagamento server-side;
- rejeição de criação cancelada e de campos server-side;
- valor zero/negativo rejeitado na API e no banco;
- edição de aberta;
- transição aberta->paga;
- Dinheiro/PIX pagos vinculam ao Caixa elegível;
- outras formas não vinculam;
- paga fica imutável;
- cancelada fica imutável;
- PATCH direto para cancelada falha;
- estorno de aberta e paga cria livro canônico e cancela a saída;
- segundo estorno falha sem duplicação;
- DELETE físico continua bloqueado;
- RBAC de leitura e gestão;
- migration/provisionamento tenant inclui os novos invariantes;
- suíte completa, Pint, Larastan nível 8, Composer validate/audit, manifesto Supabase e guards do repositório no SHA final.

## Fora de escopo

Plano de contas, DRE, centro de custo, conciliação bancária, contas bancárias, fornecedor estruturado, anexos de comprovantes, recorrência, parcelamento de despesa, aprovação multinível, sangria/suprimento, múltiplos caixas por operador, ERP, convênios/faturas e cutover do frontend.

## Critério de conclusão

A fase só pode ser declarada concluída quando o código, migration, contrato e documentação estiverem coerentes com esta spec, todos os testes e guards passarem no mesmo SHA final e nenhuma escrita tiver sido feita no Supabase live.
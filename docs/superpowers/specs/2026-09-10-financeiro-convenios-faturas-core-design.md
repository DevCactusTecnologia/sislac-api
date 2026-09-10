# Financeiro — Convênios/Faturas Core — Design

## Contexto

Esta onda continua a migração financeira do SISLAC para Laravel/PostgreSQL tenant após Financeiro Core, Totais Canônicos, Caixa Operacional e Saídas. A arquitetura permanece:

> Frontend lê. Backend calcula. Nada é destrutivo.

O PostgreSQL físico do laboratório é a autoridade do domínio. O Supabase live é usado apenas como baseline read-only durante a migração.

## Objetivo

Portar o núcleo operacional de Convênios/Faturas sem antecipar Glosa/Reapresentação, Competência, TISS, XML, integração com operadora, ERP ou cutover do frontend.

A entrega deve permitir:

- listar convênios existentes;
- consultar A Receber por convênio com a mesma regra de elegibilidade usada para faturar;
- consultar exames faturáveis de um convênio em um período;
- criar fatura transacionalmente a partir de exames elegíveis;
- listar e detalhar faturas;
- registrar pagamento integral de fatura;
- cancelar fatura aberta sem apagar histórico;
- estornar fatura paga usando `financeiro_estornos` com `origem_tipo = 'fatura'`;
- preservar invariantes também contra escrita direta no banco.

## Fontes de verdade recuperadas

O histórico aprovado de Convênios 2.0 fixa as seguintes decisões:

1. somente exames `finalizado` são elegíveis a faturamento;
2. item de fatura cancelada deve poder voltar ao conjunto faturável;
3. cancelamento é formal e não apaga itens;
4. fatura paga não é editada/cancelada diretamente: usa estorno;
5. `subtotal` e `total` são calculados no banco, nunca pelo frontend;
6. fatura paga permanece fora de `atendimento_pagamentos` e do Caixa Operacional;
7. Glosa/Reapresentação e Competência são ondas posteriores.

O live atual confirma o domínio `convenios`, `convenio_faturas`, `convenio_fatura_itens`, protocolo `FAT-AAAA-NNNNNNN`, estorno tipo `fatura`, proteção de fatura paga e cancelamento sem DELETE.

### Drift live conhecido e correção canônica

O live atual possui `UNIQUE(atendimento_exame_id)` em `convenio_fatura_itens`, mas também mantém itens após cancelar a fatura. Isso impede refaturar o mesmo exame cancelado e contradiz a regra histórica aprovada de reentrada no conjunto faturável.

O Laravel não replica esse drift. Em vez de unicidade histórica global:

- o item antigo permanece intacto;
- a criação de um novo item bloqueia a linha de `atendimento_exames` com `FOR UPDATE`;
- o banco rejeita outro item se já existir vínculo com fatura cujo status não seja `cancelada`;
- vínculo pertencente apenas a fatura cancelada não bloqueia novo faturamento.

Isso preserva histórico e garante, sob concorrência, no máximo uma fatura ativa para o mesmo exame.

## Modelo de dados da onda

### `convenios`

Tabela tenant sem `tenant_id`:

- `id` integer;
- `codigo` integer nullable, gerado pelo servidor quando ausente;
- `nome` text obrigatório;
- `registro_ans` text default `''`;
- `tipo` text default `Saúde`, limitado a `Saúde|Odontológico|Ocupacional`;
- `tabela` text default `Própria`, limitado a `CBHPM|TUSS|Própria`;
- `dias_retorno` integer >= 0;
- `ativo` boolean;
- `libera_fluxo_sem_pagamento` boolean;
- `prazo_faturamento_dias` integer >= 0;
- timestamps.

O convênio `id=0`, nome `Particular`, é criado na migration. Ele não pode ser excluído, renomeado nem desativado. Esta onda não cria CRUD administrativo de convênios; apenas leitura para suportar o faturamento.

### `convenio_faturas`

- `id` bigint;
- `convenio_id` FK para `convenios`;
- `codigo` text único e server-owned;
- `periodo_inicio` date;
- `periodo_fim` date;
- `subtotal` numeric(14,2) server-owned;
- `desconto` numeric(14,2) >= 0;
- `total` numeric(14,2) server-owned;
- `status` em `aberta|paga|cancelada` nesta onda;
- `forma_pagamento` text default `''`;
- `data_pagamento` date nullable;
- `observacao` text default `''`;
- `assinatura_protocolo` text nullable;
- `cancelada_em` timestamptz nullable;
- `cancelada_por` uuid nullable;
- `motivo_cancelamento` text nullable;
- timestamps.

`periodo_inicio <= periodo_fim`. Fatura para `convenio_id=0` é rejeitada.

`codigo` segue `FAT-AAAA-NNNNNNN`, gerado atomicamente usando `protocolo_sequence` com prefixo `FAT` e ano da criação.

### `convenio_fatura_itens`

- `id` bigint;
- `fatura_id` FK;
- `atendimento_exame_id` FK;
- `valor` numeric(14,2) snapshot server-owned do `atendimento_exames.valor`;
- `created_at` timestamptz.

Não há `tenant_id` e não há `UNIQUE(atendimento_exame_id)` histórico global.

## Elegibilidade canônica

Um exame é faturável quando simultaneamente:

- `atendimento_exames.status = 'finalizado'`;
- `cobranca_destino = 'convenio'`;
- `convenio_cobranca_id = convenio_id` escolhido;
- `atendimentos.data::date` está entre `periodo_inicio` e `periodo_fim` inclusive;
- não existe item do mesmo exame ligado a fatura com status diferente de `cancelada`.

A consulta de A Receber e a consulta de itens faturáveis usam exatamente esse predicado. A criação da fatura revalida a mesma regra dentro de transação e o PostgreSQL a repete em trigger para escrita direta.

## Criação de fatura

Entrada cliente:

- `convenio_id`;
- `periodo_inicio`;
- `periodo_fim`;
- `exame_ids` não vazio, sem duplicidade;
- `desconto` opcional, default 0;
- `observacao` opcional.

Campos server-owned devem estar ausentes: `codigo`, `subtotal`, `total`, `status`, `forma_pagamento`, `data_pagamento`, `assinatura_protocolo`, campos de cancelamento.

Fluxo:

1. iniciar transação;
2. bloquear convênio e validar ativo/não-Particular;
3. bloquear `atendimento_exames` solicitados em ordem determinística;
4. revalidar elegibilidade de todos;
5. criar cabeçalho `aberta`;
6. inserir itens somente com IDs; trigger determina o snapshot `valor`;
7. triggers recalculam `subtotal` e `total` no banco;
8. retornar fatura atualizada.

Se qualquer exame for inelegível, a transação inteira falha; não existe fatura parcial.

## Totais

Fórmula canônica:

```text
subtotal = Σ convenio_fatura_itens.valor
total    = MAX(subtotal - desconto, 0)
```

O cálculo vive no PostgreSQL. O Laravel não mantém um segundo calculador.

## Pagamento

Somente fatura `aberta` pode ser paga.

Entrada:

- `forma_pagamento` obrigatória e não vazia;
- `data_pagamento` opcional; default server-side para a data atual;
- `observacao` opcional para complementar a observação da fatura somente antes da transição terminal.

A operação usa `FOR UPDATE`, revalida o estado e persiste `status='paga'`. O valor recebido é o `total` canônico da fatura; não existe pagamento parcial nesta onda.

Pagamento de fatura não cria `atendimento_pagamentos` e não recebe `caixa_sessao_id`, mesmo para Dinheiro/PIX. Integração de fatura com Caixa não é introduzida nesta onda.

## Cancelamento e estorno

### Cancelamento de fatura aberta

- exige `gestao_financeira`;
- exige motivo não vazio;
- bloqueia fatura com `FOR UPDATE`;
- somente `aberta` pode seguir por esse endpoint;
- persiste `status='cancelada'`, `cancelada_em`, `cancelada_por`, `motivo_cancelamento`;
- não apaga cabeçalho nem itens;
- exames voltam a ser elegíveis porque o vínculo histórico cancelado não é considerado ativo.

### Estorno de fatura paga

- exige `gestao_financeira`;
- exige motivo;
- bloqueia fatura com `FOR UPDATE`;
- somente `paga` pode ser estornada;
- cria primeiro `financeiro_estornos` com `origem_tipo='fatura'`, `origem_id`, `valor=total`, ator e motivo;
- depois muda a fatura para `cancelada` e grava metadados de cancelamento;
- a constraint única de `financeiro_estornos` impede segundo estorno;
- a operação é transacional.

## Imutabilidade e DELETE

- `codigo` e assinatura são imutáveis;
- fatura `paga` ou `cancelada` é terminal para campos de negócio;
- a única transição permitida a partir de `paga` é `paga → cancelada` quando já existe o estorno canônico da mesma fatura;
- itens de fatura não são editados depois de inseridos;
- DELETE físico de `convenio_faturas` e `convenio_fatura_itens` é bloqueado no PostgreSQL;
- não existe rota HTTP DELETE.

## HTTP mínimo

Leitura (`visualizar_financeiro`):

```text
GET /api/financeiro/convenios
GET /api/financeiro/a-receber/convenios
GET /api/financeiro/convenios/{id}/itens-faturaveis
GET /api/financeiro/faturas
GET /api/financeiro/faturas/{id}
```

Mutação (`gestao_financeira`):

```text
POST /api/financeiro/faturas
POST /api/financeiro/faturas/{id}/pagar
POST /api/financeiro/faturas/{id}/cancelar
POST /api/financeiro/faturas/{id}/estorno
```

Nenhuma rota de CRUD administrativo de convênio ou DELETE entra nesta onda.

## Respostas e paginação

- dinheiro serializado com duas casas decimais;
- listagem de faturas usa cursor estável por `created_at DESC, id DESC`;
- A Receber por convênio retorna agregação derivada do conjunto elegível, sem tabela de saldo paralela;
- detalhe da fatura inclui itens com snapshot financeiro e dados clínicos mínimos necessários para identificação.

## Segurança

Cadeia de requisição permanece:

```text
Bearer Supabase
→ usuário central já correlacionado
→ membership ativa
→ tenant autorizado
→ permissão
→ banco PostgreSQL físico do laboratório
```

Permissões:

- `visualizar_financeiro`: leituras;
- `gestao_financeira`: criar/pagar/cancelar/estornar.

Nenhuma decisão de autorização usa metadata do token. Nenhuma função nova precisa de `SECURITY DEFINER`; triggers/helpers usam `SECURITY INVOKER`, `SET search_path = ''` e referências schema-qualified.

## Concorrência

- criação bloqueia exames solicitados em ordem por ID;
- trigger de item também bloqueia o exame-alvo antes de verificar vínculo ativo;
- pagamento/cancelamento/estorno bloqueiam a fatura;
- numeração FAT usa upsert atômico em `protocolo_sequence`;
- não há Redis, fila, lock distribuído, cache financeiro ou event bus.

## Fora do escopo

- Glosa formal e Reapresentação;
- Competência mensal e estado `fechada`;
- recebimento parcial de fatura;
- TISS/SADT/XML;
- integração com operadora;
- ERP, DRE, conciliação bancária, centro de custo;
- CRUD administrativo completo de convênios/tabelas de preço;
- frontend/cutover;
- alteração do Supabase live.

## Critério de conclusão

A onda só é concluída quando, no mesmo SHA:

- migrations tenant reproduzíveis;
- invariantes PostgreSQL testadas em Postgres real;
- API e RBAC verdes;
- concorrência e imutabilidade cobertas;
- Composer validate/audit verde;
- manifesto Supabase íntegro;
- Pint verde;
- Larastan nível 8 verde;
- Pest completo verde;
- guards de repositório verdes;
- comparação com `a951b3f093a2ae088a18f98802b034597e359d1d` mostra `behind_by = 0`;
- nenhuma escrita/DDL foi feita no Supabase live.

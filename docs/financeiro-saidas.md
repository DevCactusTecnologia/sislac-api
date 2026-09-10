# Financeiro — Saídas / Despesas

## Objetivo

Esta subfase leva para o Laravel/PostgreSQL definitivo a operação mínima de Saídas/Despesas do SISLAC: listar, cadastrar, corrigir lançamento ainda aberto, efetivar pagamento e estornar formalmente. `financeiro_saidas` permanece a única fonte de verdade; não existe livro paralelo nem expansão para ERP.

O princípio operacional continua sendo:

> Frontend lê. Backend calcula. Nada é destrutivo.

O domínio vive somente no banco PostgreSQL físico do laboratório. O banco central não recebe dados de Saídas e o Supabase live permanece somente como baseline de concordância durante a migração.

## Máquina de estados

`status` é o estado financeiro canônico:

```text
aberta -> paga -> cancelada
   \--------------> cancelada
```

- `aberta`: pode ter dados operacionais corrigidos;
- `paga`: lançamento efetivado e congelado para edição normal;
- `cancelada`: estado terminal produzido exclusivamente por estorno formal.

`foi_pago` não é autoridade do cliente. O PostgreSQL o deriva do estado:

```text
aberta    => foi_pago = false
paga      => foi_pago = true
cancelada => foi_pago = false
```

Para Saída aberta, `data_pagamento` é `NULL`. Ao pagar, a data informada é usada ou o servidor adota a data corrente. Ao estornar uma Saída que já esteve paga, a data histórica de pagamento é preservada.

## Protocolo server-side

Todo novo lançamento recebe protocolo oficial no formato:

```text
SAI-AAAA-0000001
```

O ano vem de `data` e o contador usa `protocolo_sequence` de forma atômica por prefixo/ano. O cliente não envia protocolo oficial e o valor se torna imutável após a criação.

`assinatura_protocolo` também não é campo controlável pelo cliente. Quando já existe valor não nulo, o PostgreSQL impede sua substituição. Esta fase não cria serviço HMAC ou infraestrutura adicional de assinatura.

## API

As únicas rotas operacionais da fase são:

```text
GET   /api/financeiro/saidas
POST  /api/financeiro/saidas
PATCH /api/financeiro/saidas/{id}
POST  /api/financeiro/saidas/{id}/estorno
```

Não existe `DELETE /api/financeiro/saidas/{id}`. O PostgreSQL também bloqueia exclusão física diretamente na tabela.

### Listagem

`GET /api/financeiro/saidas` exige `visualizar_financeiro` e consulta somente o tenant atual.

Filtros suportados:

- `search`, aplicado com `ILIKE` a protocolo, descrição, tipo e destino;
- `status`;
- `date_from`;
- `date_to`;
- `limit`, com default 50 e teto 100;
- cursor opaco estável baseado em `(data,id)`.

A ordenação canônica é `data DESC, id DESC`.

### Criação

`POST /api/financeiro/saidas` exige `gestao_financeira`.

Obrigatórios:

- `descricao`;
- `valor` maior que zero, com no máximo duas casas decimais;
- `tipo_despesa`;
- `destino_pagamento`.

Opcionais:

- `data`;
- `data_vencimento`;
- `forma_pagamento`;
- `status`, somente `aberta` ou `paga`;
- `data_pagamento` quando a criação já for paga.

Sem `status`, a Saída nasce `aberta`. É permitido cadastrar diretamente como `paga` para preservar o fluxo operacional simples. `cancelada` nunca pode nascer por POST comum.

Campos server-side explicitamente rejeitados: `id`, `protocolo`, `assinatura_protocolo`, `foi_pago`, `caixa_sessao_id`, `created_at` e `updated_at`.

### Correção e pagamento

`PATCH /api/financeiro/saidas/{id}` exige `gestao_financeira` e bloqueia a linha com `FOR UPDATE` antes de avaliar o estado.

Somente Saída `aberta` pode ser modificada. Enquanto aberta, podem ser corrigidos descrição, valor, tipo, destino, data, vencimento e forma de pagamento. O mesmo PATCH pode executar a única transição regular de estado, `aberta -> paga`, incluindo `forma_pagamento` e `data_pagamento`.

Saída já paga ou cancelada retorna conflito para tentativa de edição normal. PATCH direto para `cancelada`, reabertura de estado terminal e alteração de campos server-side são rejeitados.

## Estorno formal

`POST /api/financeiro/saidas/{id}/estorno` exige `gestao_financeira` e payload com `motivo` obrigatório.

A operação é transacional e segue esta ordem:

1. bloqueia a Saída com `FOR UPDATE`;
2. verifica se já existe estorno;
3. normaliza o motivo;
4. cria `financeiro_estornos` com `origem_tipo = 'saida'`, origem, motivo, valor e usuário responsável;
5. somente depois muda a Saída para `cancelada`.

A ordem é intencional: o trigger PostgreSQL que autoriza a transição para `cancelada` exige que o estorno canônico já exista na mesma transação.

O estorno pode partir de Saída aberta ou paga. A linha original não é apagada e preserva protocolo, valor, descrição, classificação, destino, forma, datas e `caixa_sessao_id`. Um segundo estorno retorna conflito e a UNIQUE física de `financeiro_estornos(origem_tipo, origem_id)` impede duplicação mesmo fora da API.

## Integração com Caixa

Saídas pagas em `Dinheiro` ou `PIX` continuam usando o vínculo automático implementado no Caixa Operacional.

Como `financeiro_saidas` ainda não possui unidade própria, o PostgreSQL só preenche `caixa_sessao_id` quando existe exatamente uma sessão aberta em todo o tenant. Se houver zero ou mais de uma sessão aberta, não há associação automática. Outras formas de pagamento não são vinculadas.

Esta fase não inventa `unidade_id` para Saídas e não altera a semântica aprovada do Caixa.

Quando uma Saída vinculada é estornada, o vínculo histórico com a sessão é preservado. O fechamento do Caixa exclui o valor porque consulta o estorno canônico de origem `saida`, e não porque apaga ou desvincula o lançamento.

## Integridade PostgreSQL

A migration `2026_09_10_000800_harden_financeiro_saidas.php` endurece a tabela existente sem recriá-la. Entre as garantias físicas estão:

- `valor > 0`;
- estados limitados a `aberta|paga|cancelada`;
- coerência entre `status`, `foi_pago` e `data_pagamento`;
- protocolo automático e imutável;
- assinatura não nula imutável;
- criação direta como cancelada bloqueada;
- Saída paga e cancelada protegidas contra reescrita de negócio;
- transição para cancelada condicionada a estorno canônico;
- DELETE físico bloqueado;
- `updated_at` atualizado server-side;
- vínculo com Caixa fechado protegido.

As funções PostgreSQL novas ou substituídas usam `SECURITY INVOKER`, `SET search_path = ''` e referências schema-qualified. Nenhum `SECURITY DEFINER` foi introduzido.

## Autorização

A divisão de acesso é deliberadamente pequena:

- listar: `visualizar_financeiro`;
- criar: `gestao_financeira`;
- corrigir/efetivar pagamento: `gestao_financeira`;
- estornar: `gestao_financeira`.

O papel `financeiro` recebe a gestão necessária. Um perfil que tenha somente `visualizar_financeiro` consegue consultar, mas não criar, alterar ou estornar.

## Concorrência e histórico

Criação de protocolo é atômica no PostgreSQL. PATCH e estorno usam transação e lock pessimista na Saída. Comandos concorrentes sobre a mesma linha são serializados e o segundo comando relê o estado já consolidado antes de decidir.

Erros de operação são mapeados como `404` para inexistência, `409` para conflito de estado/estorno duplicado, `422` para payload inválido e `403` para falta de permissão. Violações inesperadas do banco não são mascaradas como sucesso.

## Concordância com o Supabase

O Supabase live foi utilizado apenas como fonte de leitura/concordância para schema e semântica. Nenhuma escrita, DDL ou migration foi executada no Supabase live durante esta fase.

A coluna `forma_pagamento` existente é utilizada diretamente; o backend Laravel não reproduz marcações legadas embutidas em `descricao`.

## Testes e provisionamento

A suíte cobre, em PostgreSQL tenant real e na fronteira HTTP:

- protocolo server-side e sua imutabilidade;
- valor positivo e coerência de estados;
- listagem, filtros e cursor estável;
- criação aberta e paga;
- rejeição de campos server-side;
- correção de Saída aberta;
- pagamento único e congelamento posterior;
- vínculo de Dinheiro/PIX ao Caixa elegível e não vínculo das demais formas;
- estorno de Saída aberta e paga;
- preservação de data de pagamento e vínculo histórico do Caixa;
- motivo obrigatório e estorno duplicado bloqueado;
- estados terminais imutáveis;
- ausência de rota DELETE e bloqueio de DELETE físico;
- RBAC de leitura e gestão;
- provisionamento de novo tenant incluindo a migration `000800`.

A conclusão da fase exige Composer validate, Composer audit, manifesto Supabase, Pint, Larastan nível 8, Pest completo e Guards de repositório verdes no mesmo SHA.

## Fora do escopo

Plano de contas, DRE, centro de custo, conciliação bancária, contas bancárias, fornecedor estruturado, anexos de comprovantes, recorrência, parcelamento de despesa, aprovação multinível, sangria/suprimento, caixa por operador, ERP, Convênios/Faturas e cutover do frontend permanecem fora desta subfase.

# Financeiro Core — pacientes

## Escopo

Esta subfase migra para o Laravel somente o núcleo financeiro ligado a pacientes:

- A Receber de pacientes;
- Recebimentos de pacientes;
- registro aditivo de pagamento;
- estorno formal de pagamento.

Convênios/faturas, Saídas, Caixa, resumo financeiro e cutover do frontend permanecem fora desta subfase.

O contrato executável está em `docs/contracts/financeiro-core.json`.

## Fontes de verdade usadas

A implementação foi derivada de três fontes verificadas em conjunto:

1. Supabase live em modo de leitura, incluindo `financeiro_a_receber_v2`, `financeiro_entradas`, `private.financeiro_estornar`, `atendimento_pagamentos` e `financeiro_estornos`;
2. frontend atual `DevCactusTecnologia/sislacprivado@57cc9be96703a41b207d530088369da1cc23cd94`, especialmente `useAReceberPacientes.ts` e `financeiroStore.ts`;
3. documentação oficial Laravel 13 e Supabase/PostgreSQL.

Referências oficiais relevantes:

- Laravel 13 — Query Builder / Pessimistic Locking: <https://laravel.com/docs/13.x/queries#pessimistic-locking>
- Laravel 13 — Validation / `missing`: <https://laravel.com/docs/13.x/validation#rule-missing>
- Supabase — Database Functions: <https://supabase.com/docs/guides/database/functions>

O Supabase live não é alterado por esta subfase. Ele é baseline de concordância e permanece read-only no processo de migração.

## Regra financeira canônica

Para paciente, o backend deriva os valores a partir dos lançamentos persistidos:

```text
valor devido
  = soma de atendimento_exames.valor
    onde status != cancelado
    e cobranca_destino != convenio

valor pago
  = soma de atendimento_pagamentos.valor
    onde status_pagamento != estornado

saldo
  = valor devido - valor pago
```

Saldo negativo não é um estado operacional válido. Novo pagamento maior que o saldo é rejeitado tanto pela Action Laravel quanto por trigger PostgreSQL.

O cliente não envia `valor_pago`, `saldo`, `status_pagamento`, subtotal financeiro ou qualquer outro campo derivado como autoridade.

## A Receber

Endpoint:

```text
GET /api/financeiro/a-receber/pacientes
```

Permissão: `visualizar_atendimentos`.

Filtros:

- `search`;
- `date_from` e `date_to` como timestamps aceitos pelo Laravel, preservados como limites inclusivos;
- `status=pendente|parcial`;
- cursor por `cursor_data` + `cursor_id`;
- `limit` entre 1 e 100.

Ordenação: `data DESC, id DESC`.

A resposta preserva os campos de compatibilidade relevantes do RPC Supabase (`ref_id`, `tipo`, `protocolo`, `data`, `desde`, `quem`, `convenio_nome`, `valor_total`, `valor_pago`, `saldo`, `status`, `qtd_exames`, `qtd_pacientes`) e inclui aliases explícitos úteis ao novo consumidor (`id`, `paciente_nome`, `paciente_cpf`, `unidade_id`).

### Divergência corrigida

No Supabase live, a CTE `base` de `financeiro_a_receber_v2` soma exames cobrados do paciente sem filtrar `atendimento_exames.status = 'cancelado'`.

Isso diverge da própria função canônica `recompute_atendimento_completo`, que exclui exame cancelado ao calcular `total_devido_paciente`. Foram localizados atendimentos ativos afetados por essa diferença no baseline live.

O Laravel segue a invariante canônica: **exame cancelado não gera dívida**. Essa decisão é registrada no contrato, não é uma suposição silenciosa.

## Recebimentos

Endpoint:

```text
GET /api/financeiro/recebimentos/pacientes
```

Permissão: `visualizar_atendimentos`.

A consulta replica a parcela de paciente da view live `financeiro_entradas`:

- uma linha por `atendimento_pagamentos`;
- somente pagamento não estornado;
- atendimento cancelado fica fora;
- registro com correspondente em `financeiro_estornos` também fica fora;
- o status retornado é o status financeiro agregado de `atendimentos`, como na view atual;
- ordenação por `data DESC, pagamento_id DESC`.

A consulta é derivada. Não existe tabela Laravel paralela de Entradas.

## Registrar pagamento

Endpoint:

```text
POST /api/financeiro/atendimentos/{id}/pagamentos
```

Permissão: `registrar_pagamento`.

Payload aceito:

```json
{
  "tipo": "PIX",
  "valor": "40.00",
  "data": "2026-09-09T20:00:00-03:00",
  "observacao": "Primeira parcela"
}
```

`data` e `observacao` são opcionais. O valor deve ser positivo e possuir no máximo duas casas decimais.

A Action executa dentro de uma transação e bloqueia o atendimento com `FOR UPDATE`. Depois do lock, o saldo é recalculado usando as linhas autoritativas. Isso impede duas requisições concorrentes de consumirem o mesmo saldo.

O PostgreSQL repete a proteção crítica em trigger `BEFORE INSERT`: mesmo uma escrita que contorne a API não pode criar sobrepagamento.

Pagamento é sempre **aditivo**. Um novo pagamento cria uma nova linha; não substitui linhas anteriores.

## Estorno

Endpoint:

```text
POST /api/financeiro/pagamentos/{id}/estorno
```

Permissão: `gestao_financeira`.

Payload:

```json
{
  "motivo": "Pagamento lançado em duplicidade"
}
```

O fluxo é transacional:

1. bloqueia o pagamento com `FOR UPDATE`;
2. rejeita pagamento já estornado ou com estorno já persistido;
3. muda somente `status_pagamento` para `estornado`;
4. o trigger existente recompõe o status agregado do atendimento;
5. grava `financeiro_estornos` com tipo, origem, motivo, valor original e usuário;
6. qualquer falha reverte a transação inteira.

`financeiro_estornos` possui unicidade por `(origem_tipo, origem_id)` e é append-only.

A recepção continua podendo registrar pagamento, mas `registrar_pagamento` **não** concede estorno. `gestao_financeira` é uma permissão separada; o perfil `financeiro` a recebe e `admin` continua autorizado pela regra global existente.

## Imutabilidade

Após a entrada desta subfase:

- `PATCH /api/atendimentos/{id}` exige que `pagamentos` esteja ausente (`missing`);
- `UpdateAtendimento` não possui mais `replacePagamentos`;
- `DELETE` físico de `atendimento_pagamentos` é bloqueado no PostgreSQL;
- campos de negócio do pagamento não podem ser atualizados;
- a única UPDATE aceita no pagamento é a transição para `status_pagamento = 'estornado'`;
- pagamento estornado não pode voltar a `efetuado`;
- estorno não pode ser alterado nem apagado.

Essa regra elimina o antigo comportamento destrutivo de apagar e recriar pagamentos durante edição do atendimento.

## PostgreSQL e segurança

As funções de trigger introduzidas nesta subfase seguem a orientação oficial do Supabase:

- `SECURITY INVOKER`;
- `SET search_path = ''`;
- objetos referenciados com schema `public` explícito.

Nenhuma função `SECURITY DEFINER` nova é necessária para o Financeiro Core no banco tenant, porque autorização HTTP é resolvida antes do domínio e a conexão server-side já opera no banco físico autorizado do laboratório.

## Critério de conclusão

Esta subfase só pode ser marcada concluída quando o mesmo SHA passar:

```text
composer validate --strict
composer audit --locked
Pint
Larastan nível 8
Pest paralelo em PostgreSQL real
check-no-central-in-tenant
check-backend-scope
check-postgres-bootstrap
check-database-contract
check-file-size
```

Além disso, os testes do Financeiro Core devem provar pelo menos:

- dívida exclui exame cancelado e convênio;
- pagamento parcial e total;
- zero, negativo e sobrepagamento rejeitados;
- sobrepagamento rejeitado também no banco;
- Recebimentos exclui cancelados/estornados;
- motivo obrigatório;
- permissão separada de estorno;
- estorno preserva o pagamento original e reabre o saldo;
- segundo estorno rejeitado;
- pagamento não pode ser apagado/adulterado;
- estorno é append-only;
- PATCH de atendimento não pode substituir pagamentos.

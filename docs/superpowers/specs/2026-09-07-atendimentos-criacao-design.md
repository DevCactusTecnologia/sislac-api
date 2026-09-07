# Criação de Atendimentos — Design

## Objetivo

Reimplementar no Laravel, sem redesenhar o negócio, o fluxo executável de criação de atendimento hoje usado pelo `sislacprivado` no SHA `0760c6123f3062842eaff5f7304b6460c00c058d` e pelo projeto Supabase `eramenhnqcbyctyiqwlm`.

Esta especificação cobre **somente a criação transacional** de `atendimentos + atendimento_exames + atendimento_pagamentos`. Leitura, atualização, cancelamento, Coleta, Análise, Resultados e Financeiro completo ficam fora desta unidade e só começam após esta ficar integralmente verde.

## Fontes normativas

Ordem de precedência:

1. comportamento executável do `sislacprivado` no SHA fixado;
2. schema/funções/policies atualmente ativos no Supabase;
3. documentação oficial Laravel 13;
4. documentação oficial PostgreSQL 17;
5. documentação oficial Supabase para segurança e comparação de comportamento.

Arquivos de referência do frontend:

- `src/data/atendimentoStore/mutations.ts`;
- `supabase/functions/create-atendimento/index.ts`;
- `supabase/migrations/20260903030541_407ab057-94ea-46b8-9614-62753f209edb.sql`;
- `src/integrations/supabase/types.ts`.

## Regra principal

A migração é tecnológica. O Laravel deve reproduzir o resultado funcional do fluxo atual, incluindo defaults, normalizações, estados, idempotência, atomicidade e efeitos persistidos.

Uma vulnerabilidade ou detalhe específico da plataforma Supabase não precisa ser copiado quando o Laravel consegue produzir o mesmo resultado de forma mais segura. Em particular, o Laravel não reproduzirá uma RPC pública `SECURITY DEFINER`: autenticação/autorização ocorrerão no pipeline HTTP e a escrita será executada em transação PostgreSQL normal no banco tenant.

## Arquitetura mínima

```text
POST /api/atendimentos
  -> auth:sanctum
  -> EnsureTenantContext
  -> RequireTenantPermission:criar_atendimento
  -> StoreAtendimentoRequest
  -> CreateAtendimento
  -> DB::transaction()
       -> atendimento
       -> exames
       -> pagamentos
  -> JSON/Resource
```

Não criar Repository, DTO, Command Bus, Event Bus, CQRS, fila, cache ou interface abstrata nesta unidade.

## Entrada compatível com o frontend

O request Laravel aceitará a estrutura já preparada pelo fluxo atual:

```json
{
  "atendimento": {
    "data": "2026-09-07T12:00:00-03:00",
    "paciente_id": 123,
    "paciente_nome": "Paciente Sintético",
    "paciente_cpf": "12345678901",
    "paciente_nascimento": "1990-01-01",
    "solicitante": "Dr. Teste",
    "convenio_id": 0,
    "convenio_nome": "Particular",
    "unidade_id": "und-001",
    "motivo_cancelamento": null,
    "idempotency_key": "00000000-0000-4000-8000-000000000001",
    "origem_atendimento": "INTERNO",
    "jejum": false,
    "prioridade_clinica": "normal",
    "guia_numero": null,
    "observacoes_assistente": null
  },
  "exames": [],
  "pagamentos": []
}
```

O backend não receberá `status_atendimento`, `status_pagamento`, `protocolo`, `subtotal`, `desconto_total`, `acrescimo_total` ou `total` como autoridade do cliente.

## Normalizações e defaults obrigatórios

Conforme o fluxo atual:

- `data`: timestamp recebido; se ausente, usar `now()`;
- `paciente_id`: nullable;
- `paciente_nome`: obrigatório;
- `paciente_cpf`: somente dígitos; vazio permitido para compatibilidade do atendimento;
- `paciente_nascimento`: nullable;
- `solicitante`: default `""`;
- `convenio_id`: default `0`;
- `convenio_nome`: default `"Particular"`;
- `unidade_id`: default `"und-001"`;
- `origem_atendimento`: default `"INTERNO"`;
- `jejum`: default `false`;
- `prioridade_clinica`: default `"normal"`;
- `observacoes_assistente`: nullable;
- `motivo_cancelamento`: nullable;
- `idempotency_key`: UUID nullable.

## Exames

Cada item deve preservar os campos consumidos pelo fluxo atual:

- `nome_exame` obrigatório;
- `exame_id` UUID nullable;
- `material_id` UUID nullable;
- `status`, default `pendente` quando não informado;
- `valor`, default `0`;
- `valor_original`, fallback para `valor`;
- `ordem`, default `0` no contrato de banco, mas o frontend atual envia `idx + 1`;
- `cobranca_destino`, default `paciente`;
- `convenio_cobranca_id` nullable;
- `amostra_seq`, default `1`;
- `grupo_exame_id`, UUID; gerar server-side se não vier;
- `tipo_processo`, default `INTERNO`;
- `lab_apoio_id` nullable;
- `solicitante`, default `""`.

O frontend atual já resolve exames terceirizados como `status = digitado`. O Laravel deve preservar o status recebido e não reinterpretá-lo.

## Pagamentos

Cada pagamento aceita:

- `tipo`: obrigatório para persistir; item sem tipo é ignorado no fluxo atual;
- `valor`: numeric, default `0`;
- `data`: timestamp; fallback `now()`.

## Idempotência

`idempotency_key` é a chave de idempotência da criação.

Comportamento obrigatório:

1. se a chave já existir, não criar novas linhas;
2. retornar o atendimento existente;
3. marcar a resposta com `duplicate: true`;
4. duas requisições concorrentes com a mesma chave devem resultar em um único atendimento persistido.

A garantia física será feita com índice/constraint unique no PostgreSQL e tratamento transacional da colisão.

## Atomicidade

Atendimento, exames e pagamentos pertencem à mesma transação.

Qualquer falha persistente em exame ou pagamento deve desfazer tudo. Não é permitido atendimento parcialmente criado.

## Protocolo e guia

O `protocolo` é gerado pelo backend/banco, nunca confiado ao cliente. A implementação deve reproduzir o formato observado no Supabase antes do GREEN final; o formato será registrado no contrato versionado `docs/contracts/atendimentos-criacao.json` a partir do catálogo ativo.

`guia_numero` preserva o valor enviado quando houver e deve ser retornado na resposta. Se o Supabase ativo gerar guia automaticamente por trigger/rotina, esse comportamento deverá ser identificado pelo teste de concordância antes da aprovação da unidade.

## Resposta

Sucesso novo:

```json
{
  "ok": true,
  "atendimento_id": 1,
  "protocolo": "...",
  "guia_numero": null
}
```

Sucesso idempotente:

```json
{
  "ok": true,
  "duplicate": true,
  "atendimento_id": 1,
  "protocolo": "...",
  "guia_numero": null
}
```

Erros de validação usam `422`. Falhas inesperadas não expõem SQLSTATE, SQL, stack trace, credenciais ou dados clínicos em mensagens públicas.

## Autorização

A rota exige `criar_atendimento` no tenant ativo. `X-Tenant` continua sendo apenas seletor de membership previamente validada.

Nenhuma autorização é delegada ao payload ou ao frontend.

## Schema desta unidade

Criar no banco tenant apenas as colunas de `atendimentos`, `atendimento_exames` e `atendimento_pagamentos` necessárias para reproduzir a criação atual e suportar as ondas seguintes sem perda de dados.

A migration deve reproduzir tipos/defaults/constraints observados no Supabase ativo, exceto objetos estritamente específicos da plataforma. Índices só serão adicionados quando justificáveis por constraint ou consulta real.

## Testes de concordância

Usar exclusivamente fixtures sintéticas.

Casos mínimos obrigatórios:

1. atendimento sem exames/pagamentos;
2. atendimento com dois exames;
3. exame interno `pendente`;
4. exame terceirizado `digitado` recebido do frontend;
5. mesmo exame em duas amostras com `amostra_seq` distintos;
6. cobrança paciente e convênio;
7. pagamento único;
8. múltiplos pagamentos;
9. CPF formatado normalizado para dígitos;
10. defaults omitidos;
11. mesma `idempotency_key` repetida;
12. concorrência com mesma `idempotency_key`;
13. falha de exame provoca rollback integral;
14. falha de pagamento provoca rollback integral;
15. usuário sem `criar_atendimento` recebe `403`;
16. tenant A não enxerga/escreve no banco B.

## Gate de conclusão

Esta unidade só pode ser considerada 100% quando, no mesmo SHA:

- contrato Supabase ativo capturado e versionado;
- fixtures diferenciais Supabase/Laravel equivalentes nos campos persistidos e resposta relevante;
- schema testado em PostgreSQL 17 real;
- idempotência concorrente comprovada;
- rollback de exame e pagamento comprovados;
- autorização e isolamento tenant comprovados;
- Pint verde;
- Larastan nível 8 verde;
- Pest integral verde;
- Composer Audit verde;
- guards do repositório verdes;
- CI integralmente verde.

Somente depois disso pode começar a unidade de atualização/cancelamento de Atendimentos.
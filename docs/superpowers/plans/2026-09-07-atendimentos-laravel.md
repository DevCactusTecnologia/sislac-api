# Atendimentos Laravel Implementation Plan

> **Execução:** usar TDD e executar uma unidade por vez. Não avançar enquanto a unidade corrente não tiver conformidade, concordância Supabase↔Laravel e CI integralmente verdes.

**Goal:** migrar o fluxo de Atendimentos consumido pelo `sislacprivado` no SHA `0760c6123f3062842eaff5f7304b6460c00c058d`, preservando criação/edição transacional, idempotência, protocolo, exames, pagamentos e estados derivados.

**Architecture:** manter o backend simples. Caminho padrão: FormRequest → Action/Query → Eloquent/PostgreSQL → Resource. Nova camada só entra quando uma regra concreta justificar. `app/Domain/Atendimentos` não conhece `App\Platform`; autorização/tenant continuam no pipeline HTTP existente.

**Normative sources:** Laravel 13 oficial; PostgreSQL 17 oficial; documentação/changelog Supabase; frontend e banco Supabase fixados no contrato versionado.

## Regras globais

- Um banco PostgreSQL por laboratório.
- Dados de teste exclusivamente sintéticos.
- Nenhuma dependência nova sem necessidade demonstrada.
- Nenhum CQRS, repository genérico, event bus, DTO ou cache preventivo.
- Transações devem ser curtas; locks só quando necessários para concorrência real.
- O protocolo é server-side e imutável.
- `idempotency_key` é UUID opcional e impede duplicação em retry.
- `status_atendimento`, `status_pagamento`, `subtotal`, `desconto_total`, `acrescimo_total` e `total` são derivados, nunca confiados do payload.
- Criação de atendimento + exames + pagamentos é atômica.
- Permissões preservadas: `visualizar_atendimentos`, `criar_atendimento`, `editar_atendimento`, `cancelar_atendimento`, `registrar_pagamento` e permissões clínicas que apenas leem/atualizam exames nas ondas correspondentes.

---

## Unidade 0 — congelar contrato real

**Files**
- `docs/contracts/atendimentos.json`
- `tests/Contract/AtendimentosContractTest.php`

**Gate:** contrato contém SHA do frontend, três tabelas físicas, funções `create_atendimento_tx`/`update_atendimento_tx`, protocolo/idempotência, permissões e triggers críticos. CI completo verde.

## Unidade 1 — schema tenant mínimo fiel

**Files**
- migrations tenant para `atendimentos`, `atendimento_exames`, `atendimento_pagamentos` e contador de protocolo
- models correspondentes
- testes de schema/constraints/índices

**Gate:** PostgreSQL 17 reproduz invariantes usadas pelo fluxo atual. FKs para módulos ainda não migrados não serão simuladas por tabelas falsas; a divergência fica documentada e será fechada quando o módulo proprietário migrar.

## Unidade 2 — protocolo, idempotência e derivados

Implementar geração atômica de protocolo numérico com 7 dígitos, imutabilidade, idempotência concorrente e recomputação de estados/totais com testes sintéticos.

**Gate:** concorrência real em PostgreSQL, retry idempotente e estados/totais equivalentes ao Supabase.

## Unidade 3 — criação transacional

**Interface:** `POST /api/atendimentos`.

Validar e persistir atendimento + exames + pagamentos numa única transação. Resolver campos derivados somente no servidor. Erro em qualquer filho deve fazer rollback integral.

**Gate:** RED→GREEN, rollback, permissão, tenant isolation, retry idempotente e teste diferencial contra o contrato Supabase.

## Unidade 4 — leitura

**Interfaces:** listagem/página e detalhe exigidos pelo frontend baseline, com busca/indexação apenas onde há consumidor real.

**Gate:** serialização e filtros equivalentes, sem N+1, EXPLAIN para queries críticas.

## Unidade 5 — edição/cancelamento transacional

Preservar regras reais de `update_atendimento_tx`: patch escalar, identidade/ordem de exames, não rebaixar estado clínico, pagamentos, cancelamento e recomputação. Regras de faturamento/estorno que pertencem ao Financeiro serão migradas em coordenação com essa onda, sem duplicar domínio.

**Gate:** concorrência, rollback, permissões, cancelamento e concordância integral do comportamento já consumido.

## Unidade 6 — fechamento da onda

- atualizar `docs/contracts/atendimentos.json` com diferenças aprovadas;
- testes diferenciais sintéticos;
- Composer Audit;
- Pint;
- Larastan nível 8;
- Pest PostgreSQL 17;
- guards arquiteturais;
- contrato Supabase↔Laravel;
- revisão de simplicidade/YAGNI.

Somente após todos os gates passarem no mesmo SHA o PR pode sair de draft e a próxima onda pode iniciar.

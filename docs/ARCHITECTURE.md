# Arquitetura — SISLAC API

Este documento é um resumo. **A referência normativa é o ADR‑001**, publicado em
<https://claude.ai/code/artifact/520606d0-a169-4fda-83eb-786b5d2b4cb8>.

## Em uma frase

Um único deploy Laravel atende **muitos laboratórios**. Cada laboratório tem o
**seu próprio banco PostgreSQL**. Um banco central guarda os laboratórios, os
usuários e seus vínculos, os planos e o estado do provisionamento. Todos os
clientes usam o mesmo endereço (`sislac.com.br`) — **não há subdomínio por
laboratório**.

## Como um pedido chega ao banco certo

1. O usuário entra em `sislac.com.br` e faz login. O front chama a API em
   `api.sislac.com.br`.
2. Sanctum autentica o usuário pelo bearer token. O usuário vive no banco
   central.
3. O banco central diz a quais laboratórios esse usuário está vinculado
   (`memberships`). Se for um só, é ele. Se forem vários, o front manda o
   cabeçalho `X-Tenant` com o escolhido — e a API **confere o vínculo** antes
   de aceitar. Um `X-Tenant` forjado nunca dá acesso.
4. O middleware de tenancy inicializa a conexão `tenant` apontando para o banco
   daquele laboratório (`sislac_t_1001`, `sislac_t_1002`, …).
5. A partir daí, todo Eloquent da requisição cai naquele banco.

Os models do domínio (Paciente, Atendimento, Exame…) usam a conexão padrão da
requisição e não sabem nada de tenant. Só os models do plano central (Tenant,
User, Membership, Plan) declaram `protected $connection = 'central';` e vivem
em `app/Platform/`.

## Conexões (já em `config/database.php`)

| Conexão | Banco | Quem usa |
|---------|-------|----------|
| `central` (padrão) | `sislac_central` | `app/Platform`, autenticação, provisionamento |
| `tenant` | `sislac_t_XXXX` — nome definido em runtime | `app/Domain`, via middleware de tenancy (Fase 1) |

O papel `sislac_app` (sem `SUPERUSER`, `CREATEDB` ou `CREATEROLE`) é o único
usado em requisições. Criar e apagar bancos de laboratório é tarefa do pipeline
de provisionamento, com o papel `DB_ROOT_*`, nunca da API.

## Estrutura de pastas (previsão até a Fase 1)

```
app/
├─ Platform/              # models e serviços do banco central (Fase 1)
├─ Domain/                # models e serviços por laboratório (Fase 3)
└─ Http/
   ├─ Middleware/
   │  └─ EnsureTenantContext.php   # o middleware da Fase 1
   └─ Controllers/
      ├─ HealthController.php      # GET /api/health (Fase 0)
      ├─ Platform/                 # console do super-admin
      └─ Tenant/                   # API do laboratório

database/
├─ migrations/
│  ├─ central/            # aplicado uma vez no sislac_central
│  └─ tenant/             # aplicado em cada sislac_t_XXXX
└─ seeders/
   ├─ central/
   └─ tenant/

routes/
├─ api.php                # health check (Fase 0)
├─ central.php            # rotas do super-admin (Fase 1)
└─ tenant.php             # rotas do laboratório (Fase 1+)
```

## Fronteira de código

O guard `scripts/check-no-central-in-tenant.sh` (roda no CI) proíbe:

- código de tenant importar `App\Platform\*` ou pedir `DB::connection('central')`;
- código do Platform tocar `App\Domain\*` ou `DB::connection('tenant')`.

O middleware de tenancy é a única ponte. Essa disciplina evita que um bug em
endpoint de laboratório enxergue dados da plataforma ou de outro laboratório.

## Fases

| Fase | Escopo | Estado |
|------|--------|--------|
| 0 | Laravel + Docker + CI + docs + health check | **concluída** |
| 1 | Banco central, tenancy multi‑database, Sanctum, super-admin (Filament), pipeline de provisionamento | próxima |
| 2 | PDF (Chromium), WhatsApp Cloud API, integrações de apoio, Horizon/Reverb | pendente |
| 3 | Endpoints de domínio, front consome a API | pendente |
| 4 | Migração dos dados do Supabase e corte | pendente |
| 5 | SaaS comercial (self‑service, planos, cobrança) | pendente |

## Decisões que o ADR‑001 já fixa

- Backend **Laravel 13 / PHP 8.4**.
- Banco **PostgreSQL** (nunca MySQL) — o schema atual do laboratório se
  reaproveita sem tradução.
- Tenancy **um banco por laboratório**, todos num único cluster; pacote
  `stancl/tenancy` (multi‑database) como base.
- Identificação do laboratório por **vínculo do usuário + cabeçalho
  `X-Tenant`**, nunca por subdomínio.
- WhatsApp pela **Cloud API oficial da Meta** (nunca Baileys).
- PDF de laudo em **Chromium em container** (mesmo contrato do
  `api/render-pdf.ts` atual); documentos simples podem usar biblioteca PHP.
- Sanctum como autenticação, usuários no banco central.
- Segurança: MFA obrigatório para admin, rate limit fail‑closed, links de PDF
  assinados e expiráveis, CSP/HSTS no Nginx público.
- O Supabase continua em produção até a Fase 4; o Laravel é desenvolvido em
  paralelo com dados sintéticos/anonimizados e validado por **concordância**
  contra o comportamento atual antes do corte.

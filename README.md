# SISLAC — API

Backend definitivo do SISLAC em **Laravel 13 / PHP 8.4 / PostgreSQL 17**.

O frontend React/Vite existente permanece em uso durante a migração. O Supabase atual continua sendo a baseline de produção e a origem de autenticação clínica e de leitura/concordância enquanto os módulos são migrados por ondas para Laravel.

## Arquitetura

- um único deploy Laravel;
- banco PostgreSQL central para plataforma, identidade correlacionada, vínculos, Super Admin e provisionamento;
- **um banco PostgreSQL por laboratório**, isolado fisicamente;
- `stancl/tenancy` somente para inicializar e encerrar a conexão do laboratório selecionado;
- migrations centrais em `database/migrations/central`;
- migrations de laboratório em `database/migrations/tenant`;
- cada novo laboratório é criado pelo provisionador Laravel, recebe seu banco físico, executa migrations tenant e só fica ativo após smoke check bem-sucedido;
- o **Super Admin é Laravel**, server-rendered e autenticado por sessão própria;
- usuários clínicos continuam autenticando no **Supabase Auth durante a transição**; o Laravel valida o Bearer token server-side e correlaciona o UUID com `central.users` antes de aplicar memberships/permissões;
- o Supabase não é alterado destrutivamente até a equivalência de cada onda estar comprovada.

Não fazem parte da fundação atual Redis, Horizon, Reverb, workers, fila persistente, CQRS, event bus, repositories genéricos, DTOs preventivos ou pipeline frontend próprio sem consumidor real.

Veja [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Estado atual

- fundação Laravel/PostgreSQL: concluída no código;
- banco central e database-per-lab: concluídos;
- autorização central por membership: concluída;
- autenticação clínica transitória por Supabase Auth: implementada sem auto-provisionamento;
- provisionamento idempotente de novo laboratório: concluído e testado;
- conexão `supabase_source` de leitura/concordância: implementada, com sessão read-only e credencial dedicada recomendada;
- Super Admin Laravel: fundação implementada e testada;
- Pacientes: primeira onda de domínio já migrada;
- fila persistente/jobs: removidos enquanto não houver consumidor;
- `plans`/`subscriptions`: removidos da baseline de novos bancos; instalações existentes são apenas auditadas antes de qualquer cleanup físico;
- Atendimentos: permanece no PR #7 e não entra em `main` antes da conclusão/revisão desta Fase 0.

## Desenvolvimento

Pré-requisitos: PHP 8.4, Composer e PostgreSQL 17.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --path=database/migrations/central
```

Para desenvolvimento com Docker, suba somente os serviços efetivamente necessários ao fluxo atual. PostgreSQL é obrigatório para os testes de integração; serviços futuros não devem ser provisionados antecipadamente.

Health check:

```text
GET /api/health
```

A raiz `/` redireciona para `/admin`, painel Super Admin Laravel.

## Conformidade Supabase

Existem dois gates distintos e intencionais:

1. **Integridade offline do manifesto** — determinística, roda no CI sem credencial de produção;
2. **Conformidade live** — `php artisan contract:supabase-live`, executada apenas em ambiente confiável com `supabase_source` realmente read-only.

Um CI verde do manifesto não é apresentado como prova de conformidade live. Cada onda só pode ser considerada pronta para corte depois da verificação live correspondente.

## Qualidade obrigatória

Nada entra em `main` sem todos os gates disponíveis verdes no mesmo SHA:

```bash
composer validate --strict
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
```

O CI também valida:

- integridade determinística do manifesto Supabase;
- fronteira Platform ↔ Domain;
- contrato PostgreSQL-only;
- bootstrap PostgreSQL do `docker-compose.yml`;
- tamanho máximo de arquivos;
- ausência de `.env` versionado;
- ausência de infraestrutura, scaffold e pipeline frontend sem consumidor;
- escopo arquitetural do backend.

## Estrutura

```text
app/
├─ Platform/        # banco central, Supabase bridge, Super Admin, tenancy e provisionamento
├─ Domain/          # domínio executado no banco dedicado do laboratório
└─ Http/            # controllers, requests, middleware e resources

database/migrations/central/  # schema mínimo da plataforma
database/migrations/tenant/   # schema reproduzível de cada laboratório
docs/contracts/               # contratos versionados de concordância
scripts/                       # guards do CI
```

## Regra de evolução

Cada módulo entra por uma onda pequena e verificável: contrato atual → testes → implementação Laravel → conformidade live → adaptação do consumidor → corte somente após prova de equivalência. Nenhum módulo futuro deve criar abstração ou infraestrutura antes de ter consumidor real.

## Licença

Uso interno da Devcactus Tecnologia. O repositório deve permanecer privado; nenhum segredo deve ser versionado mesmo em repositório privado.

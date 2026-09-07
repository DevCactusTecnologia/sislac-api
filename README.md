# SISLAC — API

Backend definitivo do SISLAC em **Laravel 13 / PHP 8.4 / PostgreSQL 17**.

O frontend React/Vite existente permanece em uso durante a migração. O Supabase atual continua sendo a baseline de produção e a origem de leitura/concordância enquanto os módulos são migrados por ondas para Laravel.

## Arquitetura

- um único deploy Laravel;
- banco PostgreSQL central para plataforma, identidade, vínculos, Super Admin e provisionamento;
- **um banco PostgreSQL por laboratório**, isolado fisicamente;
- `stancl/tenancy` somente para inicializar e encerrar a conexão do laboratório selecionado;
- migrations centrais em `database/migrations/central`;
- migrations de laboratório em `database/migrations/tenant`;
- cada novo laboratório é criado pelo provisionador Laravel, recebe seu banco físico, executa migrations tenant e só fica ativo após smoke check bem-sucedido;
- o **Super Admin é totalmente Laravel**, server-rendered, sem criar outro SPA;
- o Supabase não é alterado destrutivamente até a equivalência de cada onda estar comprovada.

Não fazem parte da fundação atual Redis, Horizon, Reverb, CQRS, event bus, repositories genéricos, DTOs preventivos ou qualquer outra camada sem consumidor real.

Veja [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Estado atual

- fundação Laravel/PostgreSQL: concluída;
- banco central e database-per-lab: concluídos;
- Sanctum e autorização central: concluídos;
- provisionamento idempotente de novo laboratório: concluído e testado;
- Pacientes: primeira onda de domínio já migrada;
- Atendimentos: pausado até a limpeza/finalização desta fundação;
- conexão de leitura do Supabase para migração/concordância: próxima etapa;
- Super Admin Laravel: próxima etapa desta fundação.

## Desenvolvimento

Pré-requisitos: PHP 8.4, Composer, Node/Bun compatível com o lock do projeto e PostgreSQL 17.

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

A raiz `/` redireciona para `/admin`, onde ficará o painel Super Admin Laravel.

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

- contrato Supabase ↔ Laravel;
- fronteira Platform ↔ Domain;
- contrato PostgreSQL-only;
- tamanho máximo de arquivos;
- ausência de `.env` versionado;
- escopo arquitetural do backend.

## Estrutura

```text
app/
├─ Platform/        # banco central, Super Admin, tenancy e provisionamento
├─ Domain/          # domínio executado no banco dedicado do laboratório
└─ Http/            # controllers, requests, middleware e resources

database/migrations/central/  # schema da plataforma
database/migrations/tenant/   # schema reproduzível de cada laboratório
docs/contracts/               # contratos de concordância com o Supabase
scripts/                       # guards do CI
```

## Regra de evolução

Cada módulo entra por uma onda pequena e verificável: contrato Supabase → testes → implementação Laravel → concordância → adaptação do consumidor → corte somente após prova de equivalência. Nenhum módulo futuro deve criar abstração ou infraestrutura antes de ter consumidor real.

## Licença

Privado. Uso interno da Devcactus Tecnologia.

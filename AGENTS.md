# SISLAC API — guia para agentes

Leia antes de qualquer alteração. A arquitetura canônica está em `docs/ARCHITECTURE.md` e na especificação `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`.

## Objetivo definitivo

O `sislac-api` é o backend Laravel definitivo do SISLAC.

- O frontend React/Vite existente permanece durante a migração por ondas.
- O Supabase atual permanece como baseline de produção e origem de leitura/concordância durante a transição.
- O Laravel possui um banco PostgreSQL central para plataforma, identidade, vínculos, Super Admin e provisionamento.
- Cada laboratório possui seu próprio banco PostgreSQL físico.
- Cada novo laboratório é provisionado pelo Laravel: registro central → criação do banco → migrations tenant → smoke check → ativação.
- O Super Admin é totalmente Laravel e server-rendered; não criar outro SPA.

## Regras que não se negociam

1. **PostgreSQL sempre.** Produção usa PostgreSQL. Conexões centrais e tenant devem seguir `config/database.php` e `config/tenancy.php`.
2. **Database-per-lab.** O isolamento primário dos laboratórios é físico. `stancl/tenancy` é a implementação adotada; não criar uma segunda estratégia de tenancy.
3. **Banco central não contém domínio clínico.** Ele guarda apenas plataforma, usuários centrais, tenants, memberships, planos, assinaturas, provisionamento e auditoria.
4. **O banco tenant nasce das migrations versionadas.** Nunca criar schema clínico manualmente em produção.
5. **Supabase é a referência durante a migração.** Regras e dados atuais são lidos para concordância; objetos só são substituídos/cortados após equivalência comprovada.
6. **Segredos nunca no repositório.** Apenas `.env.example` é versionado.
7. **Dados de paciente são sensíveis.** Testes usam somente dados sintéticos ou anonimizados.
8. **Auditoria clínica/financeira é append-only quando o contrato exigir.** Não apagar trilhas por conveniência.
9. **Sem `git push --force` em `main`.** Nenhum commit pode quebrar os gates.
10. **Backend enxuto.** Não criar Repository, DTO, Manager, Adapter, CQRS, event bus, cache, fila, Redis, WebSocket ou pacote “para o futuro” sem consumidor real e necessidade demonstrada.
11. **Sem infraestrutura futura no `.env`, Docker ou CI.** Configuração sem código consumidor é resíduo e deve ser removida; volta somente na onda que a usar.
12. **Super Admin simples.** Usar recursos nativos do Laravel e o banco central. Não adicionar Filament/Livewire/pacote de RBAC sem necessidade comprovada.

## Fronteiras

- `app/Platform` conhece banco central, Super Admin, tenancy e provisionamento; não importa regras de `App\Domain`.
- `app/Domain` contém regras do laboratório e opera no contexto tenant; não acessa explicitamente o banco central.
- A camada HTTP pode coordenar identidade/seleção de tenant, mas não deve misturar dados centrais e clínicos na mesma persistência.
- A futura conexão `supabase_source` é somente baseline/transição e nunca será a conexão default da aplicação.

## Como trabalhar

- **Documentação oficial é normativa.** Verifique a versão instalada e documentação oficial Laravel 13, PostgreSQL, Supabase e `stancl/tenancy` antes de mudar comportamento.
- Código legado/Supabase é contrato comportamental, não justificativa para contrariar segurança ou o framework.
- Prefira Route + Middleware + FormRequest + Controller/Action/Query + Eloquent/Query Builder. Outra camada só entra quando elimina duplicação real ou isola regra relevante.
- TDD para novo comportamento: RED pelo motivo esperado → implementação mínima → GREEN → refactor apenas se necessário.
- Commits pequenos e mensagens `tipo: resumo`.
- Nenhuma onda futura enquanto a fundação/onda atual não estiver verde no mesmo SHA.

## Gates obrigatórios

```bash
composer validate --strict
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
```

Também executar todos os `scripts/check-*.sh` aplicáveis.

## Mapa

| Caminho | Responsabilidade |
|---|---|
| `app/Platform/` | central, Super Admin, tenancy, provisionamento e leitura de transição do Supabase |
| `app/Domain/` | domínio do laboratório no banco dedicado |
| `app/Domain/Pacientes/` | primeira onda de domínio já migrada |
| `config/database.php` | conexões central, tenant template e origem Supabase de transição |
| `config/tenancy.php` | database-per-lab com `DatabaseTenancyBootstrapper` |
| `database/migrations/central/` | schema central |
| `database/migrations/tenant/` | schema reproduzível dos laboratórios |
| `docs/contracts/` | baseline e contratos de concordância |
| `scripts/` | guards de CI |

## Fase atual

A fundação multi-database e Pacientes estão implementados. Atendimentos permanece pausado. A fase atual é exclusivamente: limpar resíduos, conectar leitura do Supabase, concluir o Super Admin Laravel e provar novamente provisionamento/qualidade antes da próxima onda de domínio.

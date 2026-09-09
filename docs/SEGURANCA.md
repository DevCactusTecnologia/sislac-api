# Baseline de segurança — SISLAC API

Estes controles valem para todo o backend e devem ser verificáveis por teste, guard ou prova operacional.

## Rede

- Somente 22, 80 e 443 ficam expostas na VPS.
- PostgreSQL, pgAdmin e Nginx interno não são publicados para a internet.
- TLS 1.2+ e HSTS na borda pública.
- Segredos vivem no ambiente, nunca no repositório.

## Autenticação clínica durante a transição

- O frontend clínico continua autenticando no **Supabase Auth** enquanto o cutover de identidade não for uma fase explícita.
- A API Laravel recebe Bearer token e valida a identidade server-side no Supabase Auth (`/auth/v1/user`) usando apenas URL pública e publishable key do projeto.
- A API clínica não usa pipeline stateful do Sanctum; a rota `/sanctum/csrf-cookie` fica explicitamente desativada enquanto não houver consumidor aprovado.
- Token ausente ou rejeitado falha com 401; indisponibilidade do Auth falha fechada com 503.
- Tokens e respostas internas do upstream não aparecem em payloads ou logs de erro.
- Um UUID validado no Supabase deve existir previamente em `central.users`; a requisição não cria usuário, membership ou permissão automaticamente.
- Autorização clínica continua sendo Laravel server-side por memberships/permissões. `X-Tenant` apenas seleciona um vínculo já autorizado.
- `user_metadata` editável pelo usuário nunca é fonte de autorização.

## Super Admin

O Super Admin é um contexto separado, autenticado pela sessão web Laravel e restrito ao plano central. Essa sessão não transforma o login Laravel em segunda fonte de autenticação clínica.

## Banco

- O papel HTTP (`sislac_app`) não possui `SUPERUSER`, `CREATEDB` ou `CREATEROLE`.
- Criar bancos é responsabilidade exclusiva do provisionamento auditado.
- `supabase_source` nunca é default e deve usar credencial dedicada de leitura.
- No projeto atual, `supabase_read_only_user` foi verificado com `default_transaction_read_only=on`, SELECT em `public.pacientes`, sem INSERT/UPDATE/DELETE e sem CREATE DATABASE.
- O código reforça essa proteção com `SET default_transaction_read_only = on`; a conformidade live falha se a sessão não estiver read-only.
- Auditorias clínicas/financeiras devem ser append-only quando os respectivos módulos forem migrados.
- Produção utiliza PostgreSQL; MySQL, MariaDB e SQL Server não fazem parte deste backend.

## Dados sensíveis

- Dados clínicos e identificadores pessoais só aparecem em logs quando estritamente necessários e sanitizados.
- Credenciais de integração nunca retornam em API.
- Testes usam dados sintéticos/anonimizados.
- Nenhum `service_role`, secret key ou senha do Supabase é versionado.

## Isolamento database-per-lab

Casos obrigatórios de regressão:

- usuário de A não lê nem grava B;
- `X-Tenant` forjado não inicializa B;
- membership suspensa perde acesso imediatamente;
- usuário multi-lab só entra no tenant selecionado e autorizado;
- exceção não deixa contexto tenant para a requisição seguinte;
- alternância A → B → A → B não vaza conexão/contexto.

Fila, filesystem compartilhado ou broadcasting só recebem regras tenant quando esses recursos realmente forem habilitados.

## Conformidade Supabase

Há duas garantias diferentes:

- **integridade offline:** manifesto versionado, hash e invariantes; roda no CI sem credencial de produção;
- **conformidade live:** consulta explícita read-only ao Supabase real para cada módulo migrado.

O CI não chama o manifesto estático de “conformidade Supabase ↔ Laravel”. Uma onda só é declarada conforme depois do gate live correspondente.

## Infraestrutura mínima

- fila padrão é `sync`;
- não existem tabelas `jobs`, workers, Redis ou Horizon sem consumidor runtime;
- `plans`/`subscriptions` não fazem parte da baseline de novos bancos enquanto não houver regra de negócio concreta;
- instalações existentes são auditadas antes de qualquer cleanup destrutivo.

## Gates automatizados

- `composer audit --locked --no-interaction`;
- verificação de integridade do manifesto Supabase;
- `vendor/bin/pint --test`;
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`;
- `vendor/bin/pest --parallel`;
- guards Platform ↔ Domain, PostgreSQL-only, tamanho de arquivo, `.env` e infraestrutura sem consumidor.

O gate live é deliberadamente separado do CI comum para não inserir credenciais de produção em execução de código de PR.

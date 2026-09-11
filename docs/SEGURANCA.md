# Segurança — SISLAC API

## Princípios

1. identidade clínica vem do Supabase Auth;
2. autorização vem da fonte canônica no PostgreSQL;
3. RLS permanece ativa e relevante;
4. o backend usa menor privilégio;
5. nenhuma credencial administrativa vai para o navegador;
6. operações críticas são transacionais e auditáveis;
7. erro HTTP ou exceção não deve confirmar escrita parcial.

## Autenticação

Rotas protegidas exigem `Authorization: Bearer <access_token>`. O middleware `supabase.auth` valida o token server-side no Supabase Auth e cria o principal autenticado da requisição.

A publishable key identifica o projeto e pode acompanhar a chamada de validação, mas não concede os privilégios do usuário e não substitui o Bearer token.

Não use chave administrativa, senha PostgreSQL ou credencial privilegiada no frontend.

## Banco e RLS

A API conecta ao PostgreSQL do Supabase com uma role dedicada ao backend. Essa role deve ser:

- `LOGIN` somente quando necessário à conexão;
- `NOSUPERUSER`;
- `NOCREATEDB`;
- `NOCREATEROLE`;
- `NOBYPASSRLS`;
- restrita aos schemas/objetos realmente utilizados.

Durante a requisição autenticada, `supabase.db` abre transação e aplica o papel `authenticated` e as claims do usuário para que `auth.uid()`/policies/funções operem no contexto correto.

O middleware confirma a transação apenas em resposta de sucesso. Respostas de erro e exceções executam rollback.

## Permissões

A API usa `public.has_permission(user_id, permission)` como autoridade. `permission:<nome>` deve receber nomes fixos definidos pela aplicação; o cliente não escolhe uma permissão para obter acesso.

Permissão não é derivada de `user_metadata` controlável pelo usuário, de headers arbitrários ou de parâmetros de rota.

## Validação e domínio

Requests validam formato e intenção antes do domínio. Regras concorrentes usam transação/lock no PostgreSQL. Invariantes críticas existentes no banco devem permanecer protegidas por constraints, triggers ou funções canônicas quando apropriado.

Pagamentos, estornos, caixa e despesas preservam histórico contábil. Exclusão física não deve substituir uma reversão de negócio auditável.

## CORS

`CORS_ALLOWED_ORIGINS` deve conter apenas origens explicitamente autorizadas. Em produção, remova origens locais que não tenham uso operacional.

CORS não é mecanismo de autenticação; apenas limita quais origens de navegador podem chamar a API.

## Sessão, cache e filas

A API clínica é orientada a Bearer token. Cache e sessão locais não carregam autoridade clínica. A fila permanece síncrona enquanto não houver consumidor real, reduzindo superfície operacional desnecessária.

## Logs

Nunca registrar:

- access token completo;
- senha PostgreSQL;
- chaves secretas;
- cookies/sessões;
- documentos clínicos completos sem necessidade;
- payloads sensíveis indiscriminadamente.

Registre identificadores técnicos, ação, resultado, duração e correlação suficientes para diagnóstico sem transformar log em cópia do dado clínico.

## Produção

- `APP_DEBUG=false`;
- TLS obrigatório;
- `DB_SSLMODE=require`;
- segredos fora do Git;
- permissões mínimas no sistema operacional;
- Nginx/PHP atualizados;
- acesso SSH preferencialmente por chave;
- CI verde no SHA implantado.

## Testes

A suíte automatizada usa banco PostgreSQL descartável com fixture próprio. Testes não podem apontar para o Supabase de produção.

O CI valida manifesto/lock do Composer, vulnerabilidades conhecidas, Pint, Larastan, Pest e guards estruturais.

## Incidentes

Em suspeita de vazamento:

1. revogue/rotacione a credencial afetada;
2. preserve logs e evidências necessárias;
3. verifique uso indevido e período de exposição;
4. atualize secrets nos ambientes;
5. invalide sessões/tokens quando aplicável;
6. corrija a causa-raiz antes de reabrir acesso.

Nunca publique segredos em issue, commit, PR ou conversa de suporte.

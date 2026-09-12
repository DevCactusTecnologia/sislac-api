# Arquitetura final enxuta do SISLAC

Data: 2026-09-12
Status: aguardando revisão final do usuário

## Objetivo

Definir a arquitetura final do SISLAC com o menor número possível de componentes, deixando o código fácil de entender ao abrir o projeto e removendo da arquitetura definitiva mecanismos que existem apenas por causa da migração atual.

A regra de projeto é: olhar, entender, decidir.

## Decisões aprovadas

1. Laravel 13 é a única autoridade final de autenticação, autorização, provisionamento e API.
2. O frontend React/Vite autentica na aplicação Laravel usando Laravel Sanctum.
3. Existe um banco PostgreSQL principal do SISLAC para os dados administrativos mínimos da aplicação, incluindo usuários e laboratórios.
4. Cada laboratório possui seu próprio banco PostgreSQL.
5. Cada laboratório possui sua própria conexão PostgreSQL. A localização física do banco é irrelevante para o domínio.
6. O Laravel resolve o laboratório do usuário autenticado e configura dinamicamente uma conexão PostgreSQL nativa do Laravel para aquele banco.
7. O Supabase permanece apenas como fonte temporária durante a migração do sistema atual e desaparece do runtime definitivo quando o cutover terminar.
8. O sistema não cria acoplamento a Supabase, Neon, AWS, Hostinger ou qualquer outro provedor. Para o domínio, todos são apenas PostgreSQL.

## Arquitetura final

```text
React / Vite
    |
    | Sanctum
    v
Laravel 13
    |
    +--------------------+
    |                    |
    v                    v
PostgreSQL principal     PostgreSQL do laboratório
SISLAC                   autenticado
    |                    |
    |- users             |- pacientes
    |- laboratories      |- atendimentos
    |- sessions          |- exames
    |- password_reset    |- resultados
                         |- financeiro
                         |- configurações
                         `- demais dados do laboratório
```

O banco principal não contém dados clínicos ou financeiros dos laboratórios.

## Banco principal

O banco principal é um PostgreSQL normal usado pelo Laravel.

Escopo inicial mínimo:

- `users`;
- `laboratories`;
- `sessions`;
- `password_reset_tokens`.

Nenhuma tabela de plataforma entra preventivamente. Tabelas adicionais só entram quando existir consumidor real e requisito aprovado.

### Usuários

No desenho inicial, o usuário operacional pertence ao laboratório que deve acessar. O Super Admin pertence à aplicação e não precisa de um banco clínico próprio.

O modelo deve permitir resolver diretamente:

```text
User
  -> Laboratory
      -> conexão PostgreSQL
```

Não haverá seleção de tenant enviada pelo frontend como fonte de verdade.

### Laboratórios

`laboratories` contém somente os dados necessários para administrar e localizar o banco do laboratório.

Campos conceituais mínimos:

```text
id
name
code
cnpj
email
phone
status
database_url
created_at
updated_at
```

`database_url` contém a conexão PostgreSQL do laboratório e deve ser armazenada criptografada pelo Laravel.

Estados necessários inicialmente:

```text
provisioning
active
suspended
provisioning_failed
```

Não armazenar `provider`, `project_ref`, `aws_region` ou qualquer outro detalhe específico do provedor sem consumidor real.

## Conexão dinâmica do laboratório

O Laravel usa uma conexão chamada, por exemplo, `lab`.

A cada request de domínio:

```text
request autenticada
    |
    v
user
    |
    v
laboratory
    |
    v
database_url
    |
    v
configuração runtime da conexão `lab`
    |
    v
controllers/actions/models do domínio
```

A aplicação deve usar apenas mecanismos nativos do Laravel para configurar e reconectar essa conexão.

Não usar framework de tenancy para esse objetivo.

Os models do domínio podem compartilhar uma classe base simples, por exemplo `LabModel`, cuja única responsabilidade é definir a conexão `lab`.

## Portabilidade do banco de um laboratório

Mover um laboratório para outra hospedagem não muda o domínio nem os controllers.

Fluxo:

```text
banco atual
   |
   | pg_dump / migração PostgreSQL
   v
novo PostgreSQL
   |
   | validação
   v
atualiza laboratories.database_url
   |
   v
Laravel passa a usar o novo banco
```

O destino pode ser VPS, AWS RDS, Neon, Supabase ou qualquer outro PostgreSQL compatível.

Não criar drivers por provedor.

## Supabase durante a migração

Enquanto ainda existirem módulos do `sislacprivado` lendo ou escrevendo diretamente no Supabase atual, o Supabase continua como origem temporária de dados e referência de concordância.

O estado temporário é:

```text
React
  |- módulos já migrados -> Laravel
  `- módulos ainda legados -> Supabase
```

O estado final é:

```text
React -> Laravel -> PostgreSQL
```

Após o cutover de todos os módulos, remover do runtime definitivo:

- Supabase Auth;
- Data API/PostgREST como API da aplicação;
- dependência de RLS para autorização do frontend;
- Edge Functions usadas apenas como backend do SISLAC;
- conexão `supabase_source` quando não houver mais uso de migração/concordância;
- SDK Supabase dos fluxos já migrados.

Supabase pode voltar a ser usado futuramente como fornecedor PostgreSQL ou plataforma completa, mas isso não influencia a arquitetura atual.

## Autenticação

A arquitetura final usa Laravel Sanctum para a SPA React first-party.

Fluxo:

```text
React
  |
  | cookie/sessão Sanctum
  v
Laravel
  |
  v
$request->user()
```

Não haverá no estado final:

- Bearer JWT do Supabase para requests comuns;
- middleware que consulta `/auth/v1/user`;
- bridge de identidade Supabase -> usuário Laravel;
- JWKS do Supabase;
- autenticação duplicada Laravel + Supabase.

## Autorização

Laravel é a autoridade final.

Devem ser preservados apenas os perfis e permissões que já existem como requisito do SISLAC, incluindo os perfis operacionais atuais e overrides de permissões quando necessários.

A implementação deve ser pequena e legível. Regra conceitual:

```text
permissões efetivas = defaults do perfil
                     + permissões extras
                     - permissões revogadas
```

Não adicionar pacote RBAC sem necessidade comprovada.

## Provisionamento de novo laboratório

Fluxo final aprovado:

```text
cadastro do laboratório
    |
    v
validação Laravel
    |
    v
cria Laboratory no banco principal
    |
    v
cria User administrador
    |
    v
cria banco PostgreSQL do laboratório
    |
    v
executa migrations do laboratório
    |
    v
smoke check
    |
    v
laboratory.status = active
    |
    +--> e-mail do laboratório
    `--> aviso ao Super Admin
```

O código de criação de laboratório pelo Super Admin e pelo auto-cadastro deve convergir para a mesma rotina de provisionamento.

O banco nunca é criado pelo navegador.

Credenciais administrativas de PostgreSQL nunca são enviadas ao frontend.

## E-mails de cadastro

Depois que o laboratório estiver ativo:

- enviar confirmação para o e-mail cadastrado do laboratório;
- avisar o Super Admin sobre o novo laboratório;
- nunca enviar senha em texto;
- falha de SMTP não deve apagar nem desfazer um laboratório já provisionado.

Não introduzir fila enquanto não houver necessidade operacional comprovada e aprovada.

## Impressão

O backend final usa TCPDF para as impressões oficiais.

Princípio de autoridade:

- o frontend solicita a impressão;
- o Laravel busca os dados canônicos no banco do laboratório;
- o documento oficial não aceita HTML clínico arbitrário enviado pelo navegador;
- o Laravel renderiza o documento e o TCPDF gera o PDF.

Não usar microserviço de PDF, Chromium, Storage temporário ou engine paralela sem necessidade aprovada.

## Super Admin

O Super Admin permanece server-rendered com Laravel e sessão Laravel.

Escopo inicial:

- listar laboratórios;
- criar laboratório;
- abrir detalhes;
- suspender;
- reativar;
- repetir provisionamento quando houver `provisioning_failed`.

Não adicionar preventivamente:

- Filament;
- Livewire;
- segundo SPA;
- billing;
- planos;
- assinaturas;
- impersonação;
- analytics SaaS;
- gestão multi-tenant avançada.

## Componentes explicitamente fora da arquitetura final

Quando não houver mais consumidor real após a migração, remover:

- `stancl/tenancy`;
- `config/tenancy.php`;
- `TenantWithDatabase` e traits relacionados;
- `memberships` como camada de seleção de laboratório;
- `X-Tenant` como fonte de seleção;
- middleware de Supabase Auth;
- bridge de UUID do Supabase;
- filas/Redis/Horizon preventivos;
- abstrações por provedor PostgreSQL;
- infraestrutura sem consumidor;
- qualquer código mantido apenas por hipótese futura.

A remoção deve ser feita somente quando os consumidores atuais tiverem sido substituídos e os testes demonstrarem equivalência.

## Estrutura de código desejada

Estrutura orientativa, não uma obrigação de criar camadas vazias:

```text
app/
|- Models/
|  |- User.php
|  `- Laboratory.php
|- Http/
|  `- Middleware/
|     `- UseLaboratoryDatabase.php
|- Support/
|  `- LaboratoryDatabase.php
|- Actions/
|  `- CreateLaboratory.php
`- Domain/
   |- Patients/
   |- Attendances/
   |- Routine/
   |- Results/
   `- Finance/
```

Uma classe ou diretório só existe se tiver responsabilidade real.

## Regras de simplicidade

1. Preferir recursos nativos do Laravel.
2. Uma regra de negócio deve ter uma única autoridade.
3. O frontend nunca escolhe diretamente o banco a ser usado.
4. Nenhum componente entra por possibilidade futura.
5. Nenhuma abstração por fornecedor PostgreSQL.
6. Nenhuma duplicação permanente entre Supabase e Laravel após o cutover de uma funcionalidade.
7. Migrations são a fonte de verdade do schema dos bancos de laboratório.
8. PostgreSQL é o único engine de banco do desenho final.
9. Segredos e URLs de conexão nunca entram no repositório nem em logs.
10. Código de migração é temporário e deve ter caminho explícito de remoção.

## Migração segura do desenho atual

A simplificação não será feita por remoção em massa.

Ordem:

1. congelar esta especificação como arquitetura final;
2. corrigir os gates atuais antes de mudanças estruturais;
3. mapear consumidores reais de `stancl/tenancy`, `memberships`, Supabase Auth e `X-Tenant`;
4. criar cobertura de testes para os comportamentos que permanecem;
5. introduzir autenticação Sanctum e resolução nativa de laboratório;
6. migrar consumidores para a conexão `lab`;
7. remover componentes antigos apenas quando a busca e os testes confirmarem zero consumidor;
8. continuar o cutover de módulos do Supabase para Laravel;
9. remover Supabase do runtime somente no final da migração.

## Critérios de aceite da arquitetura

A arquitetura só é considerada implementada quando:

- um usuário operacional autenticado resolve corretamente seu laboratório sem header de tenant;
- o Laravel conecta ao PostgreSQL correto daquele laboratório;
- dois laboratórios apontando para hosts PostgreSQL diferentes funcionam sem alterar domínio/controllers;
- um laboratório suspenso não acessa seu domínio;
- o Super Admin administra laboratórios sem acessar dados clínicos por padrão;
- um novo laboratório pode ser criado e provisionado com migrations reproduzíveis;
- a troca da `database_url` permite mover um laboratório validado para outro PostgreSQL;
- autenticação clínica final não depende do Supabase;
- módulos migrados não usam Supabase como caminho alternativo silencioso;
- os testes de isolamento comprovam que uma requisição do Lab A nunca opera no banco do Lab B;
- o código removido não possui consumidores restantes;
- todos os gates do backend e os testes de integração relevantes passam no mesmo SHA.

## Não objetivos

Não faz parte desta arquitetura neste momento:

- alta disponibilidade multi-região;
- read replicas;
- sharding;
- database-per-user;
- usuário operacional compartilhado entre vários laboratórios;
- billing SaaS;
- planos comerciais;
- filas distribuídas;
- Redis;
- eventos distribuídos;
- microserviços;
- abstração genérica de provedores;
- automação de criação de projetos Supabase;
- adoção preventiva de recursos Supabase futuros.

Esses itens só entram se houver requisito real posteriormente.

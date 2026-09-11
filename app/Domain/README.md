# app/Domain — regras de negócio do backend

Esta camada contém somente regras de negócio que realmente precisam executar no backend Laravel.

Regras:

- usar a conexão PostgreSQL padrão, que aponta para o projeto Supabase;
- receber identidade e autorização pelo contexto já validado na camada HTTP;
- não duplicar no Laravel constraints, triggers, funções, RLS ou outras invariantes cuja fonte canônica já é o Supabase;
- manter no Laravel apenas orquestração, validação e regras de negócio que exigem backend;
- preservar operações concorrentes críticas com transações e locks quando necessários;
- não depender de detalhes de infraestrutura que não tenham consumidor no domínio.

Pacientes, Atendimentos, Rotina e Financeiro seguem essa fronteira: Laravel expõe a API e executa o backend necessário; o Supabase permanece a fonte de verdade dos dados e invariantes de banco.

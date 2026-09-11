# app/Platform — integrações externas

Esta camada contém somente adaptadores de infraestrutura externa com consumidor real no backend.

No estado atual, a integração com o Supabase concentra:

- validação server-side da identidade recebida por Bearer token;
- representação do principal autenticado;
- autorização pela função canônica `public.has_permission`;
- suporte ao contexto PostgreSQL/RLS aplicado pela camada HTTP.

Regras:

- o Supabase permanece a fonte de verdade para PostgreSQL, Auth e Storage;
- não duplicar usuários, permissões, tenancy, schema ou configuração do Supabase no Laravel;
- não criar abstrações de infraestrutura sem consumidor real;
- regras de domínio permanecem em `App\Domain`, enquanto `App\Platform` se limita à integração técnica necessária.

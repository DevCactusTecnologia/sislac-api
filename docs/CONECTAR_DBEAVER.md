# Conectar DBeaver ao Postgres da VPS

O Postgres nunca é exposto na internet. Você chega nele a partir do seu
computador através de um **túnel SSH**: o DBeaver abre a conexão SSH para a
VPS e, dentro dela, alcança o Postgres em `localhost:5432`.

## Pré‑requisitos

- **DBeaver Community** (grátis, em <https://dbeaver.io/download/>).
- Sua chave SSH no `authorized_keys` do usuário `sislac` na VPS.
- Você consegue rodar `ssh sislac@<ip-ou-hostname-da-vps>` no terminal sem
  senha (só com a chave).

## Passo a passo

1. Abra o DBeaver, **Database → New Database Connection → PostgreSQL**.
2. Aba **Main**:
   - **Host**: `localhost`
   - **Port**: `5432`
   - **Database**: `sislac_central` (deixe assim para começar; para inspecionar
     um laboratório específico, troque depois para `sislac_t_1001`).
   - **Username**: `sislac_app`
   - **Password**: valor de `DB_PASSWORD` no `.env` da VPS
3. Aba **SSH**:
   - Marque **Use SSH Tunnel**.
   - **Host/IP**: `<ip-ou-hostname-da-vps>`
   - **Port**: `22`
   - **User Name**: `sislac`
   - **Authentication Method**: Public Key
   - **Private key**: aponte para o seu arquivo (`~/.ssh/id_ed25519` ou
     equivalente).
4. Clique **Test Connection**. Deve conectar em ~2 s.
5. Salve com um nome claro, tipo `SISLAC — Central (via VPS)`.

Repita o processo trocando o **Database** para navegar em cada laboratório
(`sislac_t_1001`, `sislac_t_1002`…). Ou salve uma conexão só apontada para o
Postgres e depois use **Databases** no painel esquerdo para trocar entre
bancos sem reconectar.

## Um tratado sobre segurança

- Nunca abra a porta 5432 no firewall. Se em algum momento você achar que
  precisa, reveja este documento — não precisa.
- Se o time cresce, use um usuário SSH separado por pessoa (`sislac-marcos`,
  `sislac-devA`) com as chaves de cada uma no `authorized_keys` respectivo, em
  vez de compartilhar `sislac`. Assim, revogar acesso é apenas apagar a chave.
- Restrinja o usuário SSH ao mínimo necessário no `sshd_config`
  (`Match User sislac-*` com `AllowTcpForwarding yes` e `X11Forwarding no`).

## Alternativa: TablePlus

O TablePlus (`tableplus.com`) segue o mesmo padrão: cria uma conexão Postgres,
aba **SSH** com host da VPS, chave privada, e o campo **Server** aponta para
`localhost:5432`. A interface é mais amigável e a versão gratuita já resolve
para uso pessoal.

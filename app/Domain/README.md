# app/Domain — o laboratório

Regras de negócio e models por laboratório: `Paciente`, `Atendimento`,
`AtendimentoExame`, `Pagamento`, `Laudo`… (Fase 3).

Regras:

- Models usam a conexão padrão da requisição (que o middleware de tenancy já
  apontou para o banco `sislac_t_XXXX` do laboratório). Nenhum model sabe qual
  laboratório é — e não precisa saber.
- Nada aqui importa `App\Platform\*` nem pede `DB::connection('central')`.
- As regras clínicas e financeiras hoje em triggers do Supabase (recálculo de
  totais e status, auditoria por diff, trilha RDC) viram serviços e testes
  aqui, validados por concordância contra o comportamento atual.

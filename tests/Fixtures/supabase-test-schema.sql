-- Fixture PostgreSQL de integração do backend Laravel com o Supabase.
-- Não é migration de produção. O schema real continua sendo governado pelo Supabase.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
        CREATE ROLE authenticated NOLOGIN;
    END IF;
    EXECUTE format('GRANT authenticated TO %I', current_user);
END
$$;

CREATE TABLE IF NOT EXISTS test_permissions (
    user_id uuid NOT NULL,
    permission text NOT NULL,
    allowed boolean NOT NULL DEFAULT true,
    PRIMARY KEY (user_id, permission)
);

CREATE OR REPLACE FUNCTION public.has_permission(p_user_id uuid, p_permission text)
RETURNS boolean
LANGUAGE sql
STABLE
SECURITY INVOKER
SET search_path = public
AS $$
    SELECT COALESCE((
        SELECT allowed
        FROM test_permissions
        WHERE user_id = p_user_id
          AND permission = p_permission
    ), false);
$$;

CREATE TABLE IF NOT EXISTS friendly_id_counters (
    scope text PRIMARY KEY,
    next_value bigint NOT NULL
);

CREATE TABLE IF NOT EXISTS pacientes (
    id bigserial PRIMARY KEY,
    nome text NOT NULL,
    nome_social text NULL,
    cpf text NULL,
    data_nascimento date NULL,
    sexo text NOT NULL DEFAULT 'M' CHECK (sexo IN ('M', 'F')),
    telefone text NOT NULL DEFAULT '',
    celular text NOT NULL DEFAULT '',
    email text NOT NULL DEFAULT '',
    cep text NOT NULL DEFAULT '',
    estado text NOT NULL DEFAULT '',
    cidade text NOT NULL DEFAULT '',
    bairro text NOT NULL DEFAULT '',
    endereco text NOT NULL DEFAULT '',
    numero text NOT NULL DEFAULT '',
    complemento text NOT NULL DEFAULT '',
    status text NOT NULL DEFAULT 'Ativo' CHECK (status IN ('Ativo', 'Inativo')),
    guardian_name text NULL,
    guardian_cpf text NULL,
    consentimento_lgpd boolean NOT NULL DEFAULT false,
    consentimento_em timestamptz NULL,
    friendly_id text NOT NULL DEFAULT '',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS pacientes_cpf_unique_nonempty ON pacientes (cpf) WHERE cpf IS NOT NULL AND cpf <> '';
CREATE UNIQUE INDEX IF NOT EXISTS pacientes_friendly_id_unique_nonempty ON pacientes (friendly_id) WHERE friendly_id <> '';
CREATE INDEX IF NOT EXISTS idx_pacientes_cursor ON pacientes (updated_at, id);

CREATE OR REPLACE FUNCTION public.block_paciente_friendly_id_update()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.friendly_id IS DISTINCT FROM OLD.friendly_id THEN
        RAISE EXCEPTION 'friendly_id de paciente é imutável' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS pacientes_block_friendly_id_update ON pacientes;
CREATE TRIGGER pacientes_block_friendly_id_update
BEFORE UPDATE OF friendly_id ON pacientes
FOR EACH ROW EXECUTE FUNCTION public.block_paciente_friendly_id_update();

CREATE TABLE IF NOT EXISTS protocolo_sequence (
    prefixo text NOT NULL,
    ano integer NOT NULL,
    ultimo_numero bigint NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (prefixo, ano)
);

CREATE TABLE IF NOT EXISTS atendimentos (
    id bigserial PRIMARY KEY,
    protocolo text NOT NULL DEFAULT '',
    data timestamptz NOT NULL DEFAULT now(),
    paciente_id bigint NULL,
    paciente_nome text NOT NULL,
    paciente_cpf text NOT NULL DEFAULT '',
    paciente_nascimento date NULL,
    solicitante text NOT NULL DEFAULT '',
    convenio_id integer NOT NULL DEFAULT 0,
    convenio_nome text NOT NULL DEFAULT 'Particular',
    unidade_id text NOT NULL DEFAULT 'und-001',
    status_atendimento text NOT NULL DEFAULT 'Pedido Realizado',
    status_pagamento text NOT NULL DEFAULT 'Pagamento pendente',
    motivo_cancelamento text NULL,
    assinatura_protocolo text NULL,
    origem_atendimento text NOT NULL DEFAULT 'INTERNO' CHECK (origem_atendimento IN ('INTERNO','WEB_AUTO','WEB_APROVADO','AGENDAMENTO')),
    tem_retificacao boolean NOT NULL DEFAULT false,
    guia_numero text NULL,
    guia_data date NULL,
    subtotal numeric(14,2) NOT NULL DEFAULT 0,
    desconto_total numeric(14,2) NOT NULL DEFAULT 0,
    acrescimo_total numeric(14,2) NOT NULL DEFAULT 0,
    total numeric(14,2) NOT NULL DEFAULT 0,
    jejum boolean NOT NULL DEFAULT false,
    observacoes_assistente text NULL,
    idempotency_key uuid NULL,
    risco_cardiovascular text NULL CHECK (risco_cardiovascular IS NULL OR risco_cardiovascular IN ('baixo','intermediario','alto','muito_alto')),
    prioridade_clinica text NOT NULL DEFAULT 'normal' CHECK (prioridade_clinica IN ('normal','urgencia','emergencia')),
    senha_consulta text NULL,
    senha_consulta_expira_em timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT atendimentos_protocolo_unique UNIQUE (protocolo)
);
CREATE UNIQUE INDEX IF NOT EXISTS atendimentos_idempotency_key_unique ON atendimentos (idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_atendimentos_cursor ON atendimentos (data, id);

CREATE TABLE IF NOT EXISTS caixa_sessoes (
    id bigserial PRIMARY KEY,
    unidade_id text NOT NULL,
    aberta_em timestamptz NOT NULL DEFAULT now(),
    fechada_em timestamptz NULL,
    responsavel_id uuid NULL,
    valor_abertura numeric(14,2) NOT NULL DEFAULT 0 CHECK (valor_abertura >= 0),
    valor_fechamento numeric(14,2) NULL,
    observacoes text NULL,
    status text NOT NULL DEFAULT 'aberta' CHECK (status IN ('aberta','fechada','cancelada')),
    fechado_por uuid NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_caixa_sessao_aberta_por_unidade ON caixa_sessoes (unidade_id) WHERE status = 'aberta';

CREATE TABLE IF NOT EXISTS atendimento_exames (
    id bigserial PRIMARY KEY,
    atendimento_id bigint NOT NULL REFERENCES atendimentos(id) ON DELETE CASCADE,
    exame_id uuid NULL,
    nome_exame text NOT NULL,
    status text NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','coletado','em_bancada','analisado','em_analise','digitado','finalizado','cancelado')),
    valor numeric(14,2) NOT NULL DEFAULT 0,
    analista text NOT NULL DEFAULT '',
    coletor text NOT NULL DEFAULT '',
    data_coleta timestamptz NULL,
    data_analise timestamptz NULL,
    data_liberacao timestamptz NULL,
    resultados jsonb NOT NULL DEFAULT '{}'::jsonb,
    motivo_cancelamento text NULL,
    ordem integer NOT NULL DEFAULT 0,
    tipo_processo text NOT NULL DEFAULT 'INTERNO' CHECK (tipo_processo IN ('INTERNO','TERCEIRIZADO')),
    lab_apoio_id uuid NULL,
    integracao_ativa boolean NOT NULL DEFAULT false,
    status_externo text NOT NULL DEFAULT 'NAO_APLICAVEL',
    protocolo_externo text NULL,
    data_envio timestamptz NULL,
    data_retorno timestamptz NULL,
    resultado_importado boolean NOT NULL DEFAULT false,
    arquivo_resultado_path text NULL,
    cobranca_destino text NOT NULL DEFAULT 'paciente' CHECK (cobranca_destino IN ('paciente','convenio')),
    convenio_cobranca_id integer NULL,
    amostra_seq integer NOT NULL DEFAULT 1,
    grupo_exame_id uuid NOT NULL DEFAULT gen_random_uuid(),
    amostra_id uuid NULL,
    is_reutilizacao boolean NOT NULL DEFAULT false,
    pop_versao text NOT NULL DEFAULT '',
    pop_id bigint NULL,
    solicitante text NOT NULL DEFAULT '',
    pdf_override_url text NULL,
    pdf_override_uploaded_by uuid NULL,
    pdf_override_uploaded_at timestamptz NULL,
    pdf_override_motivo text NULL,
    pdf_override_replaced_path text NULL,
    metodologia_snapshot text NULL,
    unidade_snapshot text NULL,
    retificado boolean NOT NULL DEFAULT false,
    retificado_at timestamptz NULL,
    valor_original numeric(14,2) NULL,
    material_id uuid NULL,
    mnemonico_exame text NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS atendimento_exames_unico_amostra
ON atendimento_exames (atendimento_id, COALESCE(exame_id::text, lower(nome_exame)), amostra_seq);

CREATE TABLE IF NOT EXISTS atendimento_pagamentos (
    id bigserial PRIMARY KEY,
    atendimento_id bigint NOT NULL REFERENCES atendimentos(id) ON DELETE CASCADE,
    tipo text NOT NULL,
    valor numeric(14,2) NOT NULL DEFAULT 0,
    data timestamptz NOT NULL DEFAULT now(),
    observacao text NOT NULL DEFAULT '',
    status_pagamento text NOT NULL DEFAULT 'efetuado',
    caixa_sessao_id bigint NULL REFERENCES caixa_sessoes(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS atendimento_audit (
    id bigserial PRIMARY KEY,
    entidade text NOT NULL,
    operacao text NOT NULL,
    acao text NOT NULL DEFAULT '',
    atendimento_id bigint NULL,
    registro_id bigint NULL,
    protocolo text NOT NULL DEFAULT '',
    paciente_nome text NOT NULL DEFAULT '',
    exame_nome text NOT NULL DEFAULT '',
    old_value jsonb NULL,
    new_value jsonb NULL,
    changed_by uuid NULL,
    changed_by_email text NOT NULL DEFAULT '',
    changed_at timestamptz NOT NULL DEFAULT now(),
    justificativa text NOT NULL DEFAULT '',
    pos_finalizacao boolean NOT NULL DEFAULT false,
    resultado_critico boolean NOT NULL DEFAULT false
);

CREATE TABLE IF NOT EXISTS lab_config (
    singleton_key smallint PRIMARY KEY CHECK (singleton_key = 1),
    rotina_fluxo_modo text NOT NULL DEFAULT 'completo' CHECK (rotina_fluxo_modo IN ('completo','coleta_resultado','apenas_resultado')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO lab_config (singleton_key, rotina_fluxo_modo) VALUES (1, 'completo') ON CONFLICT (singleton_key) DO NOTHING;

CREATE TABLE IF NOT EXISTS financeiro_estornos (
    id bigserial PRIMARY KEY,
    origem_tipo text NOT NULL CHECK (origem_tipo IN ('pagamento','fatura','saida')),
    origem_id bigint NOT NULL,
    motivo text NOT NULL CHECK (length(btrim(motivo)) > 0),
    valor numeric(14,2) NOT NULL CHECK (valor > 0),
    criado_por uuid NULL,
    criado_em timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_financeiro_estornos_origem UNIQUE (origem_tipo, origem_id)
);

CREATE TABLE IF NOT EXISTS financeiro_saidas (
    id bigserial PRIMARY KEY,
    protocolo text NOT NULL DEFAULT '',
    data timestamptz NOT NULL DEFAULT now(),
    descricao text NOT NULL DEFAULT '',
    valor numeric(10,2) NOT NULL CHECK (valor > 0),
    tipo_despesa text NOT NULL DEFAULT '',
    destino_pagamento text NOT NULL DEFAULT '',
    data_vencimento date NULL,
    foi_pago boolean NOT NULL DEFAULT false,
    data_pagamento date NULL,
    assinatura_protocolo text NULL,
    forma_pagamento text NULL,
    status text NOT NULL DEFAULT 'aberta' CHECK (status IN ('aberta','paga','cancelada')),
    caixa_sessao_id bigint NULL REFERENCES caixa_sessoes(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT financeiro_saidas_protocolo_unique UNIQUE (protocolo)
);

CREATE OR REPLACE FUNCTION public.next_atendimento_protocolo()
RETURNS text LANGUAGE plpgsql AS $$
DECLARE v_numero bigint;
BEGIN
    INSERT INTO protocolo_sequence(prefixo, ano, ultimo_numero)
    VALUES ('ATD', 0, 1)
    ON CONFLICT (prefixo, ano) DO UPDATE
       SET ultimo_numero = protocolo_sequence.ultimo_numero + 1,
           updated_at = now()
    RETURNING ultimo_numero INTO v_numero;
    RETURN lpad(v_numero::text, 7, '0');
END;
$$;

CREATE OR REPLACE FUNCTION public.assign_atendimento_protocolo()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.protocolo := public.next_atendimento_protocolo();
    NEW.assinatura_protocolo := NULL;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_atendimento_assign_protocolo ON atendimentos;
CREATE TRIGGER trg_atendimento_assign_protocolo BEFORE INSERT ON atendimentos
FOR EACH ROW EXECUTE FUNCTION public.assign_atendimento_protocolo();

CREATE OR REPLACE FUNCTION public.protect_atendimento_protocolo()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.protocolo IS DISTINCT FROM OLD.protocolo THEN
        RAISE EXCEPTION 'protocolo do atendimento é imutável' USING ERRCODE = '23514';
    END IF;
    IF OLD.assinatura_protocolo IS NOT NULL AND NEW.assinatura_protocolo IS DISTINCT FROM OLD.assinatura_protocolo THEN
        RAISE EXCEPTION 'assinatura do protocolo é imutável' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_protect_atendimento_protocolo ON atendimentos;
CREATE TRIGGER trg_protect_atendimento_protocolo BEFORE UPDATE ON atendimentos
FOR EACH ROW EXECUTE FUNCTION public.protect_atendimento_protocolo();

CREATE OR REPLACE FUNCTION public.recompute_atendimento_completo(target_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    total_exames integer;
    total_cancelados integer;
    total_finalizados integer;
    total_analise integer;
    total_coletados integer;
    total_pendentes integer;
    ativos integer;
    ativos_convenio integer;
    novo_status_at text;
    novo_status_pg text;
    v_subtotal numeric(14,2);
    v_total numeric(14,2);
    devido numeric(14,2);
    pago numeric(14,2);
    delta numeric(14,2);
BEGIN
    SELECT count(*),
           count(*) FILTER (WHERE status = 'cancelado'),
           count(*) FILTER (WHERE status = 'finalizado'),
           count(*) FILTER (WHERE status IN ('em_analise','analisado','digitado')),
           count(*) FILTER (WHERE status = 'coletado'),
           count(*) FILTER (WHERE status = 'pendente'),
           COALESCE(sum(COALESCE(valor_original, valor)) FILTER (WHERE status <> 'cancelado'), 0),
           COALESCE(sum(valor) FILTER (WHERE status <> 'cancelado'), 0),
           COALESCE(sum(valor) FILTER (WHERE status <> 'cancelado' AND cobranca_destino <> 'convenio'), 0),
           count(*) FILTER (WHERE status <> 'cancelado' AND cobranca_destino = 'convenio')
    INTO total_exames,total_cancelados,total_finalizados,total_analise,total_coletados,total_pendentes,
         v_subtotal,v_total,devido,ativos_convenio
    FROM atendimento_exames WHERE atendimento_id = target_id;

    ativos := total_exames - total_cancelados;
    IF total_exames = 0 THEN novo_status_at := 'Pedido Realizado';
    ELSIF total_cancelados = total_exames THEN novo_status_at := 'Cancelado';
    ELSIF total_pendentes >= ativos THEN novo_status_at := 'Pedido Realizado';
    ELSIF total_finalizados = ativos AND ativos > 0 THEN novo_status_at := 'Resultado Liberado';
    ELSIF total_analise > 0 THEN novo_status_at := 'Amostra Analisada';
    ELSIF total_coletados > 0 THEN novo_status_at := 'Amostra Coletada';
    ELSE novo_status_at := 'Pedido Realizado'; END IF;

    SELECT COALESCE(sum(valor),0) INTO pago
    FROM atendimento_pagamentos
    WHERE atendimento_id = target_id AND status_pagamento <> 'estornado';

    IF total_exames > 0 AND total_cancelados = total_exames THEN novo_status_pg := 'Pagamento cancelado';
    ELSIF ativos > 0 AND ativos_convenio = ativos AND pago = 0 THEN novo_status_pg := 'Faturado ao convênio';
    ELSIF devido = 0 OR pago >= devido THEN novo_status_pg := 'Pagamento efetuado';
    ELSIF pago > 0 THEN novo_status_pg := 'Pagamento parcial';
    ELSE novo_status_pg := 'Pagamento pendente'; END IF;

    delta := v_subtotal - v_total;
    UPDATE atendimentos SET
        status_atendimento = novo_status_at,
        status_pagamento = novo_status_pg,
        subtotal = v_subtotal,
        total = v_total,
        desconto_total = CASE WHEN delta > 0 THEN delta ELSE 0 END,
        acrescimo_total = CASE WHEN delta < 0 THEN -delta ELSE 0 END,
        updated_at = now()
    WHERE id = target_id;
END;
$$;

CREATE OR REPLACE FUNCTION public.recompute_from_exame()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN PERFORM public.recompute_atendimento_completo(OLD.atendimento_id); RETURN OLD; END IF;
    IF TG_OP = 'UPDATE' AND NEW.atendimento_id IS DISTINCT FROM OLD.atendimento_id THEN
        PERFORM public.recompute_atendimento_completo(OLD.atendimento_id);
    END IF;
    PERFORM public.recompute_atendimento_completo(NEW.atendimento_id);
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_recompute_atendimento_on_exame ON atendimento_exames;
CREATE TRIGGER trg_recompute_atendimento_on_exame AFTER INSERT OR UPDATE OR DELETE ON atendimento_exames
FOR EACH ROW EXECUTE FUNCTION public.recompute_from_exame();

CREATE OR REPLACE FUNCTION public.recompute_from_pagamento()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN PERFORM public.recompute_atendimento_completo(OLD.atendimento_id); RETURN OLD; END IF;
    PERFORM public.recompute_atendimento_completo(NEW.atendimento_id);
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_recompute_atendimento_on_pagamento ON atendimento_pagamentos;
CREATE TRIGGER trg_recompute_atendimento_on_pagamento AFTER INSERT OR UPDATE OR DELETE ON atendimento_pagamentos
FOR EACH ROW EXECUTE FUNCTION public.recompute_from_pagamento();

CREATE OR REPLACE FUNCTION public.guard_atendimento_exames_valor_original()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.valor_original IS NOT NULL AND NEW.valor_original IS DISTINCT FROM OLD.valor_original THEN
        NEW.valor_original := OLD.valor_original;
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_guard_atendimento_exames_valor_original ON atendimento_exames;
CREATE TRIGGER trg_guard_atendimento_exames_valor_original
BEFORE UPDATE OF valor_original ON atendimento_exames
FOR EACH ROW EXECUTE FUNCTION public.guard_atendimento_exames_valor_original();

CREATE OR REPLACE FUNCTION public.rotina_fluxo_modo()
RETURNS text LANGUAGE sql STABLE AS $$
    SELECT COALESCE((SELECT rotina_fluxo_modo FROM lab_config WHERE singleton_key = 1), 'completo');
$$;

CREATE OR REPLACE FUNCTION public.rotina_prepare_atendimento_exame()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_modo text;
BEGIN
    IF COALESCE(NEW.tipo_processo,'INTERNO') = 'TERCEIRIZADO' THEN RETURN NEW; END IF;
    v_modo := public.rotina_fluxo_modo();
    IF TG_OP = 'INSERT' AND v_modo = 'apenas_resultado' AND NEW.status = 'pendente' THEN
        NEW.status := 'analisado';
        NEW.data_coleta := COALESCE(NEW.data_coleta, now());
        NEW.data_analise := COALESCE(NEW.data_analise, NEW.data_coleta, now());
        IF btrim(NEW.coletor) = '' THEN NEW.coletor := '__SEM_REGISTRO__'; END IF;
        IF btrim(NEW.analista) = '' THEN NEW.analista := '__SEM_REGISTRO__'; END IF;
    ELSIF TG_OP = 'UPDATE' AND v_modo = 'coleta_resultado' AND OLD.status = 'pendente' AND NEW.status = 'coletado' THEN
        NEW.status := 'analisado';
        NEW.data_coleta := COALESCE(NEW.data_coleta, now());
        NEW.data_analise := COALESCE(NEW.data_analise, NEW.data_coleta, now());
        IF btrim(NEW.analista) = '' THEN NEW.analista := '__SEM_REGISTRO__'; END IF;
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_rotina_10_prepare_atendimento_exame ON atendimento_exames;
CREATE TRIGGER trg_rotina_10_prepare_atendimento_exame BEFORE INSERT OR UPDATE ON atendimento_exames
FOR EACH ROW EXECUTE FUNCTION public.rotina_prepare_atendimento_exame();

CREATE OR REPLACE FUNCTION public.rotina_validate_atendimento_exame_status()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_modo text;
BEGIN
    IF NEW.tipo_processo = 'TERCEIRIZADO' OR NEW.status IS NOT DISTINCT FROM OLD.status OR NEW.status = 'cancelado' THEN RETURN NEW; END IF;
    IF OLD.status = 'finalizado' THEN RAISE EXCEPTION 'transição de status inválida' USING ERRCODE = '23514'; END IF;
    v_modo := public.rotina_fluxo_modo();
    IF v_modo = 'apenas_resultado' AND NEW.status IN ('pendente','coletado','em_bancada') THEN
        RAISE EXCEPTION 'etapa indisponível' USING ERRCODE = '23514';
    END IF;
    IF v_modo = 'completo' AND OLD.status = 'pendente' AND NEW.status <> 'coletado' THEN
        RAISE EXCEPTION 'coleta obrigatória' USING ERRCODE = '23514';
    END IF;
    IF v_modo = 'completo' AND OLD.status IN ('coletado','em_bancada') AND NEW.status <> 'analisado' AND NOT (OLD.status='coletado' AND NEW.status='em_bancada') THEN
        RAISE EXCEPTION 'análise obrigatória' USING ERRCODE = '23514';
    END IF;
    IF v_modo = 'coleta_resultado' AND NEW.status = 'em_bancada' THEN
        RAISE EXCEPTION 'etapa indisponível' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_rotina_20_validate_atendimento_exame_status ON atendimento_exames;
CREATE TRIGGER trg_rotina_20_validate_atendimento_exame_status BEFORE UPDATE ON atendimento_exames
FOR EACH ROW EXECUTE FUNCTION public.rotina_validate_atendimento_exame_status();

CREATE OR REPLACE FUNCTION public.financeiro_validate_pagamento_insert()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE devido numeric(14,2); pago numeric(14,2);
BEGIN
    IF NEW.valor <= 0 THEN RAISE EXCEPTION 'valor do pagamento deve ser maior que zero'; END IF;
    SELECT COALESCE(sum(valor),0) INTO devido FROM atendimento_exames
    WHERE atendimento_id = NEW.atendimento_id AND status <> 'cancelado' AND cobranca_destino <> 'convenio';
    SELECT COALESCE(sum(valor),0) INTO pago FROM atendimento_pagamentos
    WHERE atendimento_id = NEW.atendimento_id AND status_pagamento <> 'estornado';
    IF NEW.status_pagamento <> 'estornado' AND NEW.valor > GREATEST(devido - pago, 0) THEN
        RAISE EXCEPTION 'valor do pagamento excede o saldo atual do atendimento';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_financeiro_validate_pagamento_insert ON atendimento_pagamentos;
CREATE TRIGGER trg_financeiro_validate_pagamento_insert BEFORE INSERT ON atendimento_pagamentos
FOR EACH ROW EXECUTE FUNCTION public.financeiro_validate_pagamento_insert();

CREATE OR REPLACE FUNCTION public.financeiro_protect_pagamento_update()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.atendimento_id IS DISTINCT FROM OLD.atendimento_id OR NEW.tipo IS DISTINCT FROM OLD.tipo OR
       NEW.valor IS DISTINCT FROM OLD.valor OR NEW.data IS DISTINCT FROM OLD.data OR
       NEW.observacao IS DISTINCT FROM OLD.observacao OR NEW.caixa_sessao_id IS DISTINCT FROM OLD.caixa_sessao_id OR
       NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'pagamento efetivo é imutável; use estorno';
    END IF;
    IF OLD.status_pagamento = 'estornado' OR NEW.status_pagamento <> 'estornado' THEN
        RAISE EXCEPTION 'única alteração permitida em pagamento é a transição para estornado';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_financeiro_protect_pagamento_update ON atendimento_pagamentos;
CREATE TRIGGER trg_financeiro_protect_pagamento_update BEFORE UPDATE ON atendimento_pagamentos
FOR EACH ROW EXECUTE FUNCTION public.financeiro_protect_pagamento_update();

CREATE OR REPLACE FUNCTION public.block_delete_financeiro()
RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'registro financeiro é append-only; use estorno'; END; $$;
DROP TRIGGER IF EXISTS trg_financeiro_block_pagamento_delete ON atendimento_pagamentos;
CREATE TRIGGER trg_financeiro_block_pagamento_delete BEFORE DELETE ON atendimento_pagamentos
FOR EACH ROW EXECUTE FUNCTION public.block_delete_financeiro();
DROP TRIGGER IF EXISTS trg_financeiro_estornos_append_only ON financeiro_estornos;
CREATE TRIGGER trg_financeiro_estornos_append_only BEFORE UPDATE OR DELETE ON financeiro_estornos
FOR EACH ROW EXECUTE FUNCTION public.block_delete_financeiro();

CREATE OR REPLACE FUNCTION public.caixa_touch_updated_at()
RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at := now(); RETURN NEW; END; $$;
DROP TRIGGER IF EXISTS trg_caixa_touch_updated_at ON caixa_sessoes;
CREATE TRIGGER trg_caixa_touch_updated_at BEFORE UPDATE ON caixa_sessoes
FOR EACH ROW EXECUTE FUNCTION public.caixa_touch_updated_at();

CREATE OR REPLACE FUNCTION public.caixa_block_session_delete()
RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'sessão de caixa não pode ser excluída'; END; $$;
DROP TRIGGER IF EXISTS trg_caixa_block_session_delete ON caixa_sessoes;
CREATE TRIGGER trg_caixa_block_session_delete BEFORE DELETE ON caixa_sessoes
FOR EACH ROW EXECUTE FUNCTION public.caixa_block_session_delete();

CREATE OR REPLACE FUNCTION public.caixa_attach_pagamento()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_unidade text; v_sessao bigint;
BEGIN
    IF NEW.caixa_sessao_id IS NOT NULL OR NEW.tipo NOT IN ('Dinheiro','PIX') THEN RETURN NEW; END IF;
    SELECT unidade_id INTO v_unidade FROM atendimentos WHERE id = NEW.atendimento_id;
    SELECT id INTO v_sessao FROM caixa_sessoes WHERE unidade_id = v_unidade AND status = 'aberta' ORDER BY aberta_em DESC,id DESC LIMIT 1;
    IF v_sessao IS NOT NULL THEN NEW.caixa_sessao_id := v_sessao; END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_caixa_attach_pagamento ON atendimento_pagamentos;
CREATE TRIGGER trg_caixa_attach_pagamento BEFORE INSERT ON atendimento_pagamentos
FOR EACH ROW EXECUTE FUNCTION public.caixa_attach_pagamento();

CREATE OR REPLACE FUNCTION public.next_financeiro_saida_protocolo(p_data timestamptz)
RETURNS text LANGUAGE plpgsql AS $$
DECLARE v_ano integer := extract(year from COALESCE(p_data,now()))::integer; v_numero bigint;
BEGIN
    INSERT INTO protocolo_sequence(prefixo,ano,ultimo_numero) VALUES ('SAI',v_ano,1)
    ON CONFLICT(prefixo,ano) DO UPDATE SET ultimo_numero=protocolo_sequence.ultimo_numero+1,updated_at=now()
    RETURNING ultimo_numero INTO v_numero;
    RETURN 'SAI-'||v_ano::text||'-'||lpad(v_numero::text,7,'0');
END;
$$;

CREATE OR REPLACE FUNCTION public.financeiro_saida_prepare()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='INSERT' AND NEW.status='cancelada' THEN RAISE EXCEPTION 'saída só pode ser cancelada por estorno' USING ERRCODE='23514'; END IF;
    IF TG_OP='INSERT' THEN NEW.protocolo := public.next_financeiro_saida_protocolo(NEW.data); NEW.assinatura_protocolo := NULL; END IF;
    IF NEW.status='paga' THEN NEW.foi_pago:=true; NEW.data_pagamento:=COALESCE(NEW.data_pagamento,CURRENT_DATE);
    ELSIF NEW.status='aberta' THEN NEW.foi_pago:=false; NEW.data_pagamento:=NULL;
    ELSE NEW.foi_pago:=false; END IF;
    NEW.updated_at:=now();
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_10_financeiro_saida_prepare ON financeiro_saidas;
CREATE TRIGGER trg_10_financeiro_saida_prepare BEFORE INSERT OR UPDATE ON financeiro_saidas
FOR EACH ROW EXECUTE FUNCTION public.financeiro_saida_prepare();

CREATE OR REPLACE FUNCTION public.financeiro_saida_protect()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.protocolo IS DISTINCT FROM OLD.protocolo THEN RAISE EXCEPTION 'protocolo da saída é imutável' USING ERRCODE='23514'; END IF;
    IF OLD.status='cancelada' AND NEW.status<>'cancelada' THEN RAISE EXCEPTION 'saída financeira terminal é imutável; use estorno' USING ERRCODE='23514'; END IF;
    IF OLD.status='paga' AND NEW.status NOT IN ('paga','cancelada') THEN RAISE EXCEPTION 'saída financeira terminal é imutável; use estorno' USING ERRCODE='23514'; END IF;
    IF NEW.status='cancelada' AND OLD.status<>'cancelada' AND NOT EXISTS (SELECT 1 FROM financeiro_estornos WHERE origem_tipo='saida' AND origem_id=OLD.id) THEN
        RAISE EXCEPTION 'saída só pode ser cancelada por estorno' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_40_financeiro_saida_protect ON financeiro_saidas;
CREATE TRIGGER trg_40_financeiro_saida_protect BEFORE UPDATE ON financeiro_saidas
FOR EACH ROW EXECUTE FUNCTION public.financeiro_saida_protect();
DROP TRIGGER IF EXISTS trg_financeiro_block_saida_delete ON financeiro_saidas;
CREATE TRIGGER trg_financeiro_block_saida_delete BEFORE DELETE ON financeiro_saidas
FOR EACH ROW EXECUTE FUNCTION public.block_delete_financeiro();

CREATE OR REPLACE FUNCTION public.audit_atendimento_change()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_old jsonb; v_new jsonb; v_atendimento bigint; v_registro bigint; v_protocolo text := ''; v_paciente text := '';
BEGIN
    IF TG_OP <> 'INSERT' THEN v_old := to_jsonb(OLD); END IF;
    IF TG_OP <> 'DELETE' THEN v_new := to_jsonb(NEW); END IF;
    IF TG_TABLE_NAME='atendimentos' THEN
        v_atendimento := COALESCE((v_new->>'id')::bigint,(v_old->>'id')::bigint); v_registro:=v_atendimento;
        v_protocolo:=COALESCE(v_new->>'protocolo',v_old->>'protocolo',''); v_paciente:=COALESCE(v_new->>'paciente_nome',v_old->>'paciente_nome','');
    ELSE
        v_atendimento:=COALESCE((v_new->>'atendimento_id')::bigint,(v_old->>'atendimento_id')::bigint);
        v_registro:=COALESCE((v_new->>'id')::bigint,(v_old->>'id')::bigint);
        SELECT protocolo,paciente_nome INTO v_protocolo,v_paciente FROM atendimentos WHERE id=v_atendimento;
    END IF;
    INSERT INTO atendimento_audit(entidade,operacao,acao,atendimento_id,registro_id,protocolo,paciente_nome,exame_nome,old_value,new_value,changed_by,changed_by_email,justificativa)
    VALUES(TG_TABLE_NAME,TG_OP,CASE TG_OP WHEN 'INSERT' THEN 'Criado' WHEN 'UPDATE' THEN 'Atualizado' ELSE 'Excluído' END,
        v_atendimento,v_registro,COALESCE(v_protocolo,''),COALESCE(v_paciente,''),
        CASE WHEN TG_TABLE_NAME='atendimento_exames' THEN COALESCE(v_new->>'nome_exame',v_old->>'nome_exame','') ELSE '' END,
        v_old,v_new,NULLIF(current_setting('app.audit_user_id',true),'')::uuid,
        COALESCE(NULLIF(current_setting('app.audit_user_email',true),''),''),COALESCE(NULLIF(current_setting('app.audit_justificativa',true),''),''));
    RETURN NULL;
END;
$$;
DROP TRIGGER IF EXISTS trg_audit_atendimentos ON atendimentos;
CREATE TRIGGER trg_audit_atendimentos AFTER INSERT OR UPDATE OR DELETE ON atendimentos FOR EACH ROW EXECUTE FUNCTION public.audit_atendimento_change();
DROP TRIGGER IF EXISTS trg_audit_atendimento_exames ON atendimento_exames;
CREATE TRIGGER trg_audit_atendimento_exames AFTER INSERT OR UPDATE OR DELETE ON atendimento_exames FOR EACH ROW EXECUTE FUNCTION public.audit_atendimento_change();
DROP TRIGGER IF EXISTS trg_audit_atendimento_pagamentos ON atendimento_pagamentos;
CREATE TRIGGER trg_audit_atendimento_pagamentos AFTER INSERT OR UPDATE OR DELETE ON atendimento_pagamentos FOR EACH ROW EXECUTE FUNCTION public.audit_atendimento_change();

GRANT USAGE ON SCHEMA public TO authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON pacientes, friendly_id_counters, protocolo_sequence, atendimentos, atendimento_exames, atendimento_pagamentos, atendimento_audit, lab_config, financeiro_estornos, financeiro_saidas, caixa_sessoes, test_permissions TO authenticated;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO authenticated;
GRANT EXECUTE ON FUNCTION public.has_permission(uuid,text) TO authenticated;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO authenticated;
REVOKE DELETE ON atendimento_pagamentos, financeiro_estornos, financeiro_saidas, caixa_sessoes FROM authenticated;

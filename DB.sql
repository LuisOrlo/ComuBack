--
-- PostgreSQL database dump
--

-- Dumped from database version 16.13
-- Dumped by pg_dump version 16.13

-- Started on 2026-06-21 17:11:11 -05

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- TOC entry 9 (class 2615 OID 27809)
-- Name: academic; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA academic;


ALTER SCHEMA academic OWNER TO postgres;

--
-- TOC entry 10 (class 2615 OID 27810)
-- Name: audit; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA audit;


ALTER SCHEMA audit OWNER TO postgres;

--
-- TOC entry 11 (class 2615 OID 27811)
-- Name: core; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA core;


ALTER SCHEMA core OWNER TO postgres;

--
-- TOC entry 12 (class 2615 OID 27812)
-- Name: finance; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA finance;


ALTER SCHEMA finance OWNER TO postgres;

--
-- TOC entry 13 (class 2615 OID 27813)
-- Name: ops; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA ops;


ALTER SCHEMA ops OWNER TO postgres;

--
-- TOC entry 14 (class 2615 OID 27814)
-- Name: people; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA people;


ALTER SCHEMA people OWNER TO postgres;

--
-- TOC entry 15 (class 2615 OID 27815)
-- Name: services; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA services;


ALTER SCHEMA services OWNER TO postgres;

--
-- TOC entry 2 (class 3079 OID 27816)
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- TOC entry 5418 (class 0 OID 0)
-- Dependencies: 2
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- TOC entry 3 (class 3079 OID 27897)
-- Name: unaccent; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;


--
-- TOC entry 5419 (class 0 OID 0)
-- Dependencies: 3
-- Name: EXTENSION unaccent; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION unaccent IS 'text search dictionary that removes accents';


--
-- TOC entry 4 (class 3079 OID 27904)
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- TOC entry 5420 (class 0 OID 0)
-- Dependencies: 4
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- TOC entry 997 (class 1247 OID 27916)
-- Name: t_estado_matricula; Type: TYPE; Schema: academic; Owner: postgres
--

CREATE TYPE academic.t_estado_matricula AS ENUM (
    'activo',
    'completado',
    'retirado',
    'reprobado'
);


ALTER TYPE academic.t_estado_matricula OWNER TO postgres;

--
-- TOC entry 1000 (class 1247 OID 27926)
-- Name: t_estado_oferta; Type: TYPE; Schema: academic; Owner: postgres
--

CREATE TYPE academic.t_estado_oferta AS ENUM (
    'pendiente',
    'confirmado',
    'en_progreso',
    'completado',
    'cancelado'
);


ALTER TYPE academic.t_estado_oferta OWNER TO postgres;

--
-- TOC entry 1003 (class 1247 OID 27938)
-- Name: t_estado_pago; Type: TYPE; Schema: finance; Owner: postgres
--

CREATE TYPE finance.t_estado_pago AS ENUM (
    'pendiente',
    'abonado',
    'pagado',
    'anulado'
);


ALTER TYPE finance.t_estado_pago OWNER TO postgres;

--
-- TOC entry 1006 (class 1247 OID 27948)
-- Name: t_estado_verificacion; Type: TYPE; Schema: finance; Owner: postgres
--

CREATE TYPE finance.t_estado_verificacion AS ENUM (
    'pendiente',
    'aprobado',
    'rechazado'
);


ALTER TYPE finance.t_estado_verificacion OWNER TO postgres;

--
-- TOC entry 1009 (class 1247 OID 27956)
-- Name: t_metodo_pago; Type: TYPE; Schema: finance; Owner: postgres
--

CREATE TYPE finance.t_metodo_pago AS ENUM (
    'efectivo',
    'transferencia',
    'deposito',
    'tarjeta',
    'otro'
);


ALTER TYPE finance.t_metodo_pago OWNER TO postgres;

--
-- TOC entry 1012 (class 1247 OID 27968)
-- Name: t_estado_reserva; Type: TYPE; Schema: services; Owner: postgres
--

CREATE TYPE services.t_estado_reserva AS ENUM (
    'reservado',
    'confirmado',
    'en_progreso',
    'completado',
    'cancelado'
);


ALTER TYPE services.t_estado_reserva OWNER TO postgres;

--
-- TOC entry 365 (class 1255 OID 27979)
-- Name: fn_actualizar_perfil_estudiante(); Type: FUNCTION; Schema: academic; Owner: postgres
--

CREATE FUNCTION academic.fn_actualizar_perfil_estudiante() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    INSERT INTO people.perfil_estudiante (persona_id, primera_matricula, ultima_matricula, total_cursos)
    VALUES (NEW.estudiante_id, NEW.fecha_inscripcion::DATE, NEW.fecha_inscripcion::DATE, 1)
    ON CONFLICT (persona_id) DO UPDATE
        SET ultima_matricula = GREATEST(people.perfil_estudiante.ultima_matricula, NEW.fecha_inscripcion::DATE),
            primera_matricula = LEAST(people.perfil_estudiante.primera_matricula, NEW.fecha_inscripcion::DATE),
            total_cursos = (
                SELECT COUNT(*)
                FROM academic.matriculas
                WHERE estudiante_id = NEW.estudiante_id
                  AND deleted_at IS NULL
            );
    RETURN NEW;
END;
$$;


ALTER FUNCTION academic.fn_actualizar_perfil_estudiante() OWNER TO postgres;

--
-- TOC entry 366 (class 1255 OID 27980)
-- Name: fn_actualizar_resumen_curso(); Type: FUNCTION; Schema: academic; Owner: postgres
--

CREATE FUNCTION academic.fn_actualizar_resumen_curso() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_curso_id UUID;
BEGIN
    v_curso_id := COALESCE(NEW.curso_abierto_id, OLD.curso_abierto_id);

    UPDATE academic.cursos_abiertos ca
    SET estudiantes_inscritos = (
            SELECT COUNT(*)
            FROM academic.matriculas m
            WHERE m.curso_abierto_id = v_curso_id
              AND m.deleted_at IS NULL
        ),
        ingreso_proyectado = (
            ca.precio_base * (
                SELECT COUNT(*)
                FROM academic.matriculas m
                WHERE m.curso_abierto_id = v_curso_id
                  AND m.deleted_at IS NULL
            )
        )
    WHERE ca.id = v_curso_id;

    RETURN COALESCE(NEW, OLD);
END;
$$;


ALTER FUNCTION academic.fn_actualizar_resumen_curso() OWNER TO postgres;

--
-- TOC entry 367 (class 1255 OID 27981)
-- Name: fn_set_updated_at(); Type: FUNCTION; Schema: core; Owner: postgres
--

CREATE FUNCTION core.fn_set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$;


ALTER FUNCTION core.fn_set_updated_at() OWNER TO postgres;

--
-- TOC entry 372 (class 1255 OID 27982)
-- Name: fn_actualizar_cuenta_cobrar(); Type: FUNCTION; Schema: finance; Owner: postgres
--

CREATE FUNCTION finance.fn_actualizar_cuenta_cobrar() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_cuenta_id     UUID;
    v_total_abonado NUMERIC(10,2);
    v_total_deuda   NUMERIC(10,2);
BEGIN
    v_cuenta_id := COALESCE(NEW.cuenta_cobrar_id, OLD.cuenta_cobrar_id);

    SELECT COALESCE(SUM(monto), 0) INTO v_total_abonado
    FROM finance.transacciones_ingreso
    WHERE cuenta_cobrar_id = v_cuenta_id;

    SELECT monto_total INTO v_total_deuda
    FROM finance.cuentas_por_cobrar
    WHERE id = v_cuenta_id;

    UPDATE finance.cuentas_por_cobrar
    SET
        monto_abonado = v_total_abonado,
        estado = CASE
            WHEN v_total_abonado >= v_total_deuda THEN 'pagado'::finance.t_estado_pago
            WHEN v_total_abonado > 0 THEN 'abonado'::finance.t_estado_pago
            ELSE 'pendiente'::finance.t_estado_pago
        END,
        updated_at = NOW()
    WHERE id = v_cuenta_id;

    RETURN COALESCE(NEW, OLD);
END;
$$;


ALTER FUNCTION finance.fn_actualizar_cuenta_cobrar() OWNER TO postgres;

--
-- TOC entry 380 (class 1255 OID 27983)
-- Name: fn_registrar_movimiento_caja(); Type: FUNCTION; Schema: finance; Owner: postgres
--

CREATE FUNCTION finance.fn_registrar_movimiento_caja() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_total_ingresos NUMERIC(14,2);
    v_total_egresos  NUMERIC(14,2);
    v_saldo          NUMERIC(14,2);
    v_tipo           VARCHAR(20);
    v_descripcion    TEXT;
BEGIN
    IF TG_TABLE_NAME = 'transacciones_ingreso' THEN
        v_tipo := 'INGRESO';
        v_descripcion := 'Ingreso registrado en cuenta por cobrar';
    ELSE
        v_tipo := 'EGRESO';
        v_descripcion := COALESCE(NEW.descripcion, OLD.descripcion);
    END IF;

    SELECT COALESCE(SUM(monto), 0) INTO v_total_ingresos FROM finance.transacciones_ingreso;
    SELECT COALESCE(SUM(monto), 0) INTO v_total_egresos  FROM finance.transacciones_egreso;
    v_saldo := v_total_ingresos - v_total_egresos;

    UPDATE finance.resumen_caja
    SET total_ingresos = v_total_ingresos,
        total_egresos  = v_total_egresos,
        saldo_actual   = v_saldo,
        updated_at     = NOW()
    WHERE id = 1;

    IF TG_OP <> 'DELETE' THEN
        INSERT INTO audit.eventos_financieros (
            tipo_evento,
            transaccion_ingreso_id,
            transaccion_egreso_id,
            monto,
            descripcion,
            fecha_evento,
            registrado_por,
            saldo_resultante
        ) VALUES (
            v_tipo,
            CASE WHEN TG_TABLE_NAME = 'transacciones_ingreso' THEN NEW.id ELSE NULL END,
            CASE WHEN TG_TABLE_NAME = 'transacciones_egreso' THEN NEW.id ELSE NULL END,
            NEW.monto,
            v_descripcion,
            COALESCE(NEW.fecha_pago, NOW()),
            NEW.registrado_por,
            v_saldo
        );
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;


ALTER FUNCTION finance.fn_registrar_movimiento_caja() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 225 (class 1259 OID 27984)
-- Name: asesorias; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.asesorias (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid,
    cliente_externo_id uuid,
    instructor_id uuid NOT NULL,
    titulo character varying(200) NOT NULL,
    descripcion text,
    modalidad character varying(50) NOT NULL,
    fecha date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    notas_sesion text,
    precio numeric(10,2) DEFAULT 0 NOT NULL,
    estado services.t_estado_reserva DEFAULT 'reservado'::services.t_estado_reserva,
    created_at timestamp with time zone DEFAULT now(),
    CONSTRAINT asesorias_modalidad_check CHECK (((modalidad)::text = ANY (ARRAY[('presencial'::character varying)::text, ('virtual'::character varying)::text]))),
    CONSTRAINT chk_asesoria_cliente CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1))
);


ALTER TABLE academic.asesorias OWNER TO postgres;

--
-- TOC entry 226 (class 1259 OID 27995)
-- Name: asistencias; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.asistencias (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    matricula_id uuid NOT NULL,
    clase_id uuid NOT NULL,
    asistio boolean DEFAULT false,
    estado character varying(20),
    observaciones text
);


ALTER TABLE academic.asistencias OWNER TO postgres;

--
-- TOC entry 227 (class 1259 OID 28002)
-- Name: asistencias_talleres; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.asistencias_talleres (
    id uuid NOT NULL,
    taller_id uuid NOT NULL,
    fecha_sesion date NOT NULL,
    asistentes integer DEFAULT 0 NOT NULL,
    capacidad_registrada integer DEFAULT 0 NOT NULL,
    observaciones text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE academic.asistencias_talleres OWNER TO postgres;

--
-- TOC entry 228 (class 1259 OID 28009)
-- Name: cambios_horario; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.cambios_horario (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    matricula_origen_id uuid NOT NULL,
    curso_abierto_nuevo_id uuid NOT NULL,
    motivo text,
    autorizado_por uuid,
    fecha_cambio timestamp with time zone DEFAULT now()
);


ALTER TABLE academic.cambios_horario OWNER TO postgres;

--
-- TOC entry 229 (class 1259 OID 28016)
-- Name: catalogo_cursos; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.catalogo_cursos (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    categoria character varying(50) NOT NULL,
    nombre character varying(200) NOT NULL,
    descripcion text,
    modulos_default smallint DEFAULT 2,
    duracion_horas_total integer,
    programa_id uuid,
    creditos integer DEFAULT 3 NOT NULL,
    horas_totales integer DEFAULT 40 NOT NULL,
    es_activo boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    imagen character varying(500),
    color character varying(7),
    CONSTRAINT catalogo_cursos_categoria_check CHECK (((categoria)::text = ANY (ARRAY[('regular'::character varying)::text, ('personalizado'::character varying)::text, ('taller'::character varying)::text])))
);


ALTER TABLE academic.catalogo_cursos OWNER TO postgres;

--
-- TOC entry 230 (class 1259 OID 28027)
-- Name: certificados; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.certificados (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    estudiante_id uuid NOT NULL,
    catalogo_id uuid NOT NULL,
    curso_abierto_id uuid,
    modulo_id uuid,
    cedula_impresa character varying(20) NOT NULL,
    fecha_emision date DEFAULT CURRENT_DATE,
    codigo_certificado character varying(100) NOT NULL,
    archivo_pdf_url character varying(500),
    estado character varying(20) DEFAULT 'generado'::character varying NOT NULL,
    fecha_entrega date,
    entregado_fisicamente boolean DEFAULT false NOT NULL,
    verificaciones_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE academic.certificados OWNER TO postgres;

--
-- TOC entry 231 (class 1259 OID 28037)
-- Name: clases; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.clases (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    modulo_id uuid NOT NULL,
    instructor_id uuid,
    fecha_clase date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    observaciones text
);


ALTER TABLE academic.clases OWNER TO postgres;

--
-- TOC entry 232 (class 1259 OID 28043)
-- Name: clases_extras; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.clases_extras (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    estudiante_id uuid NOT NULL,
    instructor_id uuid,
    curso_abierto_id uuid,
    fecha_clase date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    motivo text,
    precio numeric(10,2) DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE academic.clases_extras OWNER TO postgres;

--
-- TOC entry 233 (class 1259 OID 28051)
-- Name: comentarios_curso; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.comentarios_curso (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    curso_abierto_id uuid NOT NULL,
    autor_id uuid NOT NULL,
    comentario text NOT NULL,
    calificacion smallint,
    es_publico boolean DEFAULT false,
    created_at timestamp with time zone DEFAULT now(),
    CONSTRAINT comentarios_curso_calificacion_check CHECK (((calificacion >= 1) AND (calificacion <= 5)))
);


ALTER TABLE academic.comentarios_curso OWNER TO postgres;

--
-- TOC entry 234 (class 1259 OID 28060)
-- Name: cursos_abiertos; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.cursos_abiertos (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    catalogo_curso_id uuid NOT NULL,
    instructor_titular_id uuid,
    ciudad_id bigint,
    horario_id uuid,
    modalidad character varying(50) NOT NULL,
    capacidad_maxima smallint DEFAULT 12 NOT NULL,
    precio_base numeric(10,2) NOT NULL,
    estudiantes_inscritos integer DEFAULT 0 NOT NULL,
    ingreso_proyectado numeric(12,2) DEFAULT 0 NOT NULL,
    fecha_inicio date,
    fecha_fin date,
    estado academic.t_estado_oferta DEFAULT 'pendiente'::academic.t_estado_oferta,
    created_at timestamp with time zone DEFAULT now(),
    deleted_at timestamp with time zone,
    nombre_instancia character varying(255),
    semestre character varying(50),
    docente_id uuid,
    es_activo boolean DEFAULT true,
    observaciones text,
    updated_at timestamp without time zone,
    CONSTRAINT cursos_abiertos_modalidad_check CHECK (((modalidad)::text = ANY (ARRAY[('presencial'::character varying)::text, ('virtual'::character varying)::text])))
);


ALTER TABLE academic.cursos_abiertos OWNER TO postgres;

--
-- TOC entry 235 (class 1259 OID 28073)
-- Name: horarios; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.horarios (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nombre_referencial character varying(100) NOT NULL,
    dia_semana smallint[],
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    es_activo boolean DEFAULT true
);


ALTER TABLE academic.horarios OWNER TO postgres;

--
-- TOC entry 236 (class 1259 OID 28080)
-- Name: horarios_dias; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.horarios_dias (
    id bigint NOT NULL,
    horario_id uuid NOT NULL,
    dia_semana smallint NOT NULL
);


ALTER TABLE academic.horarios_dias OWNER TO postgres;

--
-- TOC entry 5421 (class 0 OID 0)
-- Dependencies: 236
-- Name: COLUMN horarios_dias.dia_semana; Type: COMMENT; Schema: academic; Owner: postgres
--

COMMENT ON COLUMN academic.horarios_dias.dia_semana IS '1=Lunes, 2=Martes, ..., 7=Domingo';


--
-- TOC entry 237 (class 1259 OID 28083)
-- Name: horarios_dias_id_seq; Type: SEQUENCE; Schema: academic; Owner: postgres
--

CREATE SEQUENCE academic.horarios_dias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE academic.horarios_dias_id_seq OWNER TO postgres;

--
-- TOC entry 5422 (class 0 OID 0)
-- Dependencies: 237
-- Name: horarios_dias_id_seq; Type: SEQUENCE OWNED BY; Schema: academic; Owner: postgres
--

ALTER SEQUENCE academic.horarios_dias_id_seq OWNED BY academic.horarios_dias.id;


--
-- TOC entry 238 (class 1259 OID 28084)
-- Name: inscripciones_taller; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.inscripciones_taller (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    taller_id uuid NOT NULL,
    persona_id uuid,
    precio_pagado numeric(10,2),
    estado academic.t_estado_matricula DEFAULT 'activo'::academic.t_estado_matricula,
    fecha_inscripcion timestamp with time zone DEFAULT now(),
    nombres character varying(100),
    apellidos character varying(100),
    cedula character varying(20),
    correo character varying(150),
    telefono character varying(20),
    tipo_pago character varying(20),
    monto_pagado numeric(10,2),
    metodo_pago character varying(50),
    comprobante_url character varying(500),
    pago_verificado boolean DEFAULT false NOT NULL,
    fecha_pago date,
    ocupacion character varying(100),
    direccion character varying(500),
    estado_civil character varying(20),
    fecha_nacimiento date,
    edad integer,
    cedula_url character varying(500),
    ciudad character varying(100)
);


ALTER TABLE academic.inscripciones_taller OWNER TO postgres;

--
-- TOC entry 239 (class 1259 OID 28093)
-- Name: matriculas; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.matriculas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    estudiante_id uuid,
    curso_abierto_id uuid NOT NULL,
    precio_total_legacy numeric(10,2) NOT NULL,
    tipo_pago character varying(20) DEFAULT 'completo'::character varying NOT NULL,
    voucher_url character varying(500),
    estado academic.t_estado_matricula DEFAULT 'activo'::academic.t_estado_matricula,
    fecha_inscripcion timestamp with time zone DEFAULT now(),
    deleted_at timestamp with time zone,
    solicitud_inscripcion_id uuid,
    CONSTRAINT matriculas_tipo_pago_check CHECK (((tipo_pago)::text = ANY (ARRAY[('completo'::character varying)::text, ('bono'::character varying)::text, ('abono'::character varying)::text])))
);


ALTER TABLE academic.matriculas OWNER TO postgres;

--
-- TOC entry 240 (class 1259 OID 28103)
-- Name: modulos; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.modulos (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    curso_abierto_id uuid NOT NULL,
    nombre_modulo character varying(100) NOT NULL,
    numero_orden smallint NOT NULL,
    fecha_inicio date,
    fecha_fin date,
    precio_base numeric(10,2)
);


ALTER TABLE academic.modulos OWNER TO postgres;

--
-- TOC entry 241 (class 1259 OID 28107)
-- Name: notas; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.notas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    matricula_id uuid NOT NULL,
    modulo_id uuid NOT NULL,
    calificacion numeric(4,2),
    aprobado boolean,
    observaciones text,
    CONSTRAINT notas_nota_check CHECK (((calificacion >= (0)::numeric) AND (calificacion <= (10)::numeric)))
);


ALTER TABLE academic.notas OWNER TO postgres;

--
-- TOC entry 242 (class 1259 OID 28114)
-- Name: solicitudes_inscripcion; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.solicitudes_inscripcion (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid,
    participante_externo_id uuid,
    es_participante_externo boolean DEFAULT false NOT NULL,
    curso_abierto_id uuid NOT NULL,
    monto_solicitado numeric(10,2) NOT NULL,
    tipo_pago character varying(20) DEFAULT 'completo'::character varying NOT NULL,
    archivo_comprobante_url character varying(500),
    tipo_comprobante character varying(50),
    fecha_pago_declarada date,
    estado character varying(30) DEFAULT 'registrado'::character varying NOT NULL,
    validado_por uuid,
    motivo_rechazo text,
    observaciones_validacion text,
    fecha_validacion timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    archivo_cedula_url character varying(500),
    CONSTRAINT check_estado CHECK (((estado)::text = ANY (ARRAY[('registrado'::character varying)::text, ('pendiente_validacion'::character varying)::text, ('aprobado'::character varying)::text, ('rechazado'::character varying)::text, ('matricula_creada'::character varying)::text, ('cancelado'::character varying)::text]))),
    CONSTRAINT check_excluyente_persona CHECK (((
CASE
    WHEN (persona_id IS NOT NULL) THEN 1
    ELSE 0
END +
CASE
    WHEN (participante_externo_id IS NOT NULL) THEN 1
    ELSE 0
END) = 1)),
    CONSTRAINT check_tipo_pago CHECK (((tipo_pago)::text = ANY (ARRAY[('completo'::character varying)::text, ('abono'::character varying)::text])))
);


ALTER TABLE academic.solicitudes_inscripcion OWNER TO postgres;

--
-- TOC entry 243 (class 1259 OID 28126)
-- Name: talleres; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.talleres (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nombre character varying(200) NOT NULL,
    descripcion text,
    instructor_id uuid,
    ciudad_id bigint,
    modalidad character varying(50) NOT NULL,
    capacidad_maxima smallint DEFAULT 30 NOT NULL,
    precio numeric(10,2) NOT NULL,
    fecha date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    abierto_externos boolean DEFAULT true,
    estado academic.t_estado_oferta DEFAULT 'pendiente'::academic.t_estado_oferta,
    created_at timestamp with time zone DEFAULT now(),
    fecha_fin date,
    CONSTRAINT talleres_modalidad_check CHECK (((modalidad)::text = ANY (ARRAY[('presencial'::character varying)::text, ('virtual'::character varying)::text])))
);


ALTER TABLE academic.talleres OWNER TO postgres;

--
-- TOC entry 244 (class 1259 OID 28137)
-- Name: traslados_modulo; Type: TABLE; Schema: academic; Owner: postgres
--

CREATE TABLE academic.traslados_modulo (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    matricula_origen_id uuid NOT NULL,
    modulo_origen_id uuid NOT NULL,
    curso_abierto_destino_id uuid NOT NULL,
    modulo_destino_id uuid NOT NULL,
    motivo text,
    autorizado_por uuid,
    fecha_traslado timestamp with time zone DEFAULT now()
);


ALTER TABLE academic.traslados_modulo OWNER TO postgres;

--
-- TOC entry 245 (class 1259 OID 28144)
-- Name: v_horarios_con_dias; Type: VIEW; Schema: academic; Owner: postgres
--

CREATE VIEW academic.v_horarios_con_dias AS
 SELECT h.id,
    h.nombre_referencial,
    h.hora_inicio,
    h.hora_fin,
    h.es_activo,
    COALESCE(array_agg(hd.dia_semana ORDER BY hd.dia_semana), ARRAY[]::smallint[]) AS dia_semana
   FROM (academic.horarios h
     LEFT JOIN academic.horarios_dias hd ON ((h.id = hd.horario_id)))
  GROUP BY h.id, h.nombre_referencial, h.hora_inicio, h.hora_fin, h.es_activo;


ALTER VIEW academic.v_horarios_con_dias OWNER TO postgres;

--
-- TOC entry 318 (class 1259 OID 29331)
-- Name: lineas_pago_modulo; Type: TABLE; Schema: finance; Owner: postgres
--

CREATE TABLE finance.lineas_pago_modulo (
    id uuid NOT NULL,
    matricula_id uuid NOT NULL,
    modulo_id uuid NOT NULL,
    monto_original numeric(10,2) NOT NULL,
    monto_ajustado numeric(10,2) NOT NULL,
    motivo_ajuste character varying(255),
    ajustado_por uuid,
    fecha_ajuste timestamp(0) with time zone,
    monto_abonado numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    estado character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    orden integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


ALTER TABLE finance.lineas_pago_modulo OWNER TO postgres;

--
-- TOC entry 319 (class 1259 OID 29362)
-- Name: vista_cursos_finanzas; Type: VIEW; Schema: academic; Owner: postgres
--

CREATE VIEW academic.vista_cursos_finanzas AS
 SELECT ca.id,
    cc.nombre AS curso,
    ca.modalidad,
    ca.precio_base,
    ca.capacidad_maxima,
    ca.estudiantes_inscritos,
    ca.ingreso_proyectado,
    COALESCE(( SELECT sum(lpm2.monto_ajustado) AS sum
           FROM (finance.lineas_pago_modulo lpm2
             JOIN academic.matriculas m2 ON ((m2.id = lpm2.matricula_id)))
          WHERE ((m2.curso_abierto_id = ca.id) AND (m2.deleted_at IS NULL))), COALESCE(sum(m.precio_total_legacy) FILTER (WHERE (m.deleted_at IS NULL)), (0)::numeric)) AS ingreso_matriculado_real
   FROM ((academic.cursos_abiertos ca
     JOIN academic.catalogo_cursos cc ON ((cc.id = ca.catalogo_curso_id)))
     LEFT JOIN academic.matriculas m ON ((m.curso_abierto_id = ca.id)))
  GROUP BY ca.id, cc.nombre, ca.modalidad, ca.precio_base, ca.capacidad_maxima, ca.estudiantes_inscritos, ca.ingreso_proyectado;


ALTER VIEW academic.vista_cursos_finanzas OWNER TO postgres;

--
-- TOC entry 246 (class 1259 OID 28154)
-- Name: eventos_financieros; Type: TABLE; Schema: audit; Owner: postgres
--

CREATE TABLE audit.eventos_financieros (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tipo_evento character varying(20) NOT NULL,
    transaccion_ingreso_id uuid,
    transaccion_egreso_id uuid,
    monto numeric(10,2) NOT NULL,
    descripcion text,
    fecha_evento timestamp with time zone DEFAULT now() NOT NULL,
    registrado_por uuid,
    saldo_resultante numeric(14,2) DEFAULT 0 NOT NULL,
    CONSTRAINT chk_evento_financiero_origen CHECK ((num_nonnulls(transaccion_ingreso_id, transaccion_egreso_id) = 1)),
    CONSTRAINT eventos_financieros_tipo_evento_check CHECK (((tipo_evento)::text = ANY (ARRAY[('INGRESO'::character varying)::text, ('EGRESO'::character varying)::text])))
);


ALTER TABLE audit.eventos_financieros OWNER TO postgres;

--
-- TOC entry 247 (class 1259 OID 28164)
-- Name: inicios_sesion; Type: TABLE; Schema: audit; Owner: postgres
--

CREATE TABLE audit.inicios_sesion (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    cuenta_id uuid,
    persona_id uuid,
    username character varying(100),
    ip_address inet,
    user_agent text,
    fecha_inicio timestamp with time zone DEFAULT now() NOT NULL,
    exito boolean DEFAULT true NOT NULL,
    observaciones text
);


ALTER TABLE audit.inicios_sesion OWNER TO postgres;

--
-- TOC entry 248 (class 1259 OID 28172)
-- Name: cache; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE core.cache OWNER TO postgres;

--
-- TOC entry 249 (class 1259 OID 28177)
-- Name: cache_locks; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE core.cache_locks OWNER TO postgres;

--
-- TOC entry 250 (class 1259 OID 28182)
-- Name: ciudades; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.ciudades (
    id bigint NOT NULL,
    nombre character varying(100) NOT NULL
);


ALTER TABLE core.ciudades OWNER TO postgres;

--
-- TOC entry 251 (class 1259 OID 28185)
-- Name: ciudades_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.ciudades_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.ciudades_id_seq OWNER TO postgres;

--
-- TOC entry 5423 (class 0 OID 0)
-- Dependencies: 251
-- Name: ciudades_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.ciudades_id_seq OWNED BY core.ciudades.id;


--
-- TOC entry 252 (class 1259 OID 28186)
-- Name: estudiante_segmentos; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.estudiante_segmentos (
    id uuid NOT NULL,
    nombre character varying(255) NOT NULL,
    descripcion text,
    criterios json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE core.estudiante_segmentos OWNER TO postgres;

--
-- TOC entry 253 (class 1259 OID 28191)
-- Name: failed_jobs; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection character varying(255) NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE core.failed_jobs OWNER TO postgres;

--
-- TOC entry 254 (class 1259 OID 28197)
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.failed_jobs_id_seq OWNER TO postgres;

--
-- TOC entry 5424 (class 0 OID 0)
-- Dependencies: 254
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.failed_jobs_id_seq OWNED BY core.failed_jobs.id;


--
-- TOC entry 255 (class 1259 OID 28198)
-- Name: job_batches; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE core.job_batches OWNER TO postgres;

--
-- TOC entry 256 (class 1259 OID 28203)
-- Name: jobs; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE core.jobs OWNER TO postgres;

--
-- TOC entry 257 (class 1259 OID 28208)
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.jobs_id_seq OWNER TO postgres;

--
-- TOC entry 5425 (class 0 OID 0)
-- Dependencies: 257
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.jobs_id_seq OWNED BY core.jobs.id;


--
-- TOC entry 258 (class 1259 OID 28209)
-- Name: migrations; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE core.migrations OWNER TO postgres;

--
-- TOC entry 259 (class 1259 OID 28212)
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.migrations_id_seq OWNER TO postgres;

--
-- TOC entry 5426 (class 0 OID 0)
-- Dependencies: 259
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.migrations_id_seq OWNED BY core.migrations.id;


--
-- TOC entry 260 (class 1259 OID 28213)
-- Name: model_has_permissions; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


ALTER TABLE core.model_has_permissions OWNER TO postgres;

--
-- TOC entry 261 (class 1259 OID 28216)
-- Name: model_has_roles; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id uuid NOT NULL
);


ALTER TABLE core.model_has_roles OWNER TO postgres;

--
-- TOC entry 262 (class 1259 OID 28219)
-- Name: password_reset_tokens; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE core.password_reset_tokens OWNER TO postgres;

--
-- TOC entry 263 (class 1259 OID 28224)
-- Name: permissions; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE core.permissions OWNER TO postgres;

--
-- TOC entry 264 (class 1259 OID 28229)
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.permissions_id_seq OWNER TO postgres;

--
-- TOC entry 5427 (class 0 OID 0)
-- Dependencies: 264
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.permissions_id_seq OWNED BY core.permissions.id;


--
-- TOC entry 265 (class 1259 OID 28230)
-- Name: role_has_permissions; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


ALTER TABLE core.role_has_permissions OWNER TO postgres;

--
-- TOC entry 266 (class 1259 OID 28233)
-- Name: roles; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE core.roles OWNER TO postgres;

--
-- TOC entry 267 (class 1259 OID 28238)
-- Name: roles_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.roles_id_seq OWNER TO postgres;

--
-- TOC entry 5428 (class 0 OID 0)
-- Dependencies: 267
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.roles_id_seq OWNED BY core.roles.id;


--
-- TOC entry 268 (class 1259 OID 28239)
-- Name: sessions; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE core.sessions OWNER TO postgres;

--
-- TOC entry 269 (class 1259 OID 28244)
-- Name: users; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE core.users OWNER TO postgres;

--
-- TOC entry 270 (class 1259 OID 28249)
-- Name: users_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.users_id_seq OWNER TO postgres;

--
-- TOC entry 5429 (class 0 OID 0)
-- Dependencies: 270
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.users_id_seq OWNED BY core.users.id;


--
-- TOC entry 271 (class 1259 OID 28250)
-- Name: categorias_egreso; Type: TABLE; Schema: finance; Owner: postgres
--

CREATE TABLE finance.categorias_egreso (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    tipo_general character varying(50)
);


ALTER TABLE finance.categorias_egreso OWNER TO postgres;

--
-- TOC entry 272 (class 1259 OID 28253)
-- Name: categorias_egreso_id_seq; Type: SEQUENCE; Schema: finance; Owner: postgres
--

CREATE SEQUENCE finance.categorias_egreso_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE finance.categorias_egreso_id_seq OWNER TO postgres;

--
-- TOC entry 5430 (class 0 OID 0)
-- Dependencies: 272
-- Name: categorias_egreso_id_seq; Type: SEQUENCE OWNED BY; Schema: finance; Owner: postgres
--

ALTER SEQUENCE finance.categorias_egreso_id_seq OWNED BY finance.categorias_egreso.id;


--
-- TOC entry 273 (class 1259 OID 28254)
-- Name: cuentas_por_cobrar; Type: TABLE; Schema: finance; Owner: postgres
--

CREATE TABLE finance.cuentas_por_cobrar (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    matricula_id uuid,
    inscripcion_taller_id uuid,
    reserva_aula_id uuid,
    reserva_podcast_id uuid,
    servicio_streaming_id uuid,
    servicio_produccion_id uuid,
    edicion_video_id uuid,
    alquiler_equipo_id uuid,
    clase_extra_id uuid,
    asesoria_id uuid,
    monto_total numeric(10,2) NOT NULL,
    monto_abonado numeric(10,2) DEFAULT 0,
    saldo_pendiente numeric(10,2) GENERATED ALWAYS AS ((monto_total - monto_abonado)) STORED,
    estado finance.t_estado_pago DEFAULT 'pendiente'::finance.t_estado_pago,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    solicitud_inscripcion_id uuid,
    reserva_radio_id uuid,
    es_legacy boolean DEFAULT false NOT NULL,
    CONSTRAINT chk_un_origen CHECK ((num_nonnulls(matricula_id, inscripcion_taller_id, reserva_aula_id, reserva_podcast_id, servicio_streaming_id, servicio_produccion_id, edicion_video_id, alquiler_equipo_id, clase_extra_id, asesoria_id) = 1))
);


ALTER TABLE finance.cuentas_por_cobrar OWNER TO postgres;

--
-- TOC entry 274 (class 1259 OID 28264)
-- Name: horas_instructor; Type: TABLE; Schema: finance; Owner: postgres
--

CREATE TABLE finance.horas_instructor (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    instructor_id uuid NOT NULL,
    clase_id uuid,
    curso_abierto_id uuid,
    fecha date NOT NULL,
    horas_trabajadas numeric(4,2) NOT NULL,
    tarifa_aplicada numeric(10,2) NOT NULL,
    monto_a_pagar numeric(10,2) GENERATED ALWAYS AS ((horas_trabajadas * tarifa_aplicada)) STORED,
    pagado boolean DEFAULT false,
    egreso_id uuid,
    CONSTRAINT horas_instructor_horas_trabajadas_check CHECK ((horas_trabajadas > (0)::numeric))
);


ALTER TABLE finance.horas_instructor OWNER TO postgres;

--
-- TOC entry 275 (class 1259 OID 28271)
-- Name: resumen_caja; Type: TABLE; Schema: finance; Owner: postgres
--

CREATE TABLE finance.resumen_caja (
    id smallint DEFAULT 1 NOT NULL,
    total_ingresos numeric(14,2) DEFAULT 0 NOT NULL,
    total_egresos numeric(14,2) DEFAULT 0 NOT NULL,
    saldo_actual numeric(14,2) DEFAULT 0 NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_resumen_caja_singleton CHECK ((id = 1))
);


ALTER TABLE finance.resumen_caja OWNER TO postgres;

--
-- TOC entry 276 (class 1259 OID 28280)
-- Name: transacciones_egreso; Type: TABLE; Schema: finance; Owner: postgres
--

CREATE TABLE finance.transacciones_egreso (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    categoria_id integer NOT NULL,
    descripcion text NOT NULL,
    monto numeric(10,2) NOT NULL,
    comprobante_url text,
    fecha_pago timestamp with time zone DEFAULT now(),
    registrado_por uuid,
    CONSTRAINT transacciones_egreso_monto_check CHECK ((monto > (0)::numeric))
);


ALTER TABLE finance.transacciones_egreso OWNER TO postgres;

--
-- TOC entry 277 (class 1259 OID 28288)
-- Name: transacciones_ingreso; Type: TABLE; Schema: finance; Owner: postgres
--

CREATE TABLE finance.transacciones_ingreso (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    cuenta_cobrar_id uuid NOT NULL,
    monto numeric(10,2) NOT NULL,
    metodo_pago finance.t_metodo_pago NOT NULL,
    comprobante_url text,
    fecha_pago timestamp with time zone DEFAULT now(),
    registrado_por uuid,
    observaciones text,
    estado_verificacion character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    verificado_por uuid,
    fecha_verificacion timestamp(0) without time zone,
    motivo_rechazo text,
    linea_pago_modulo_id uuid,
    CONSTRAINT transacciones_ingreso_monto_check CHECK ((monto > (0)::numeric))
);


ALTER TABLE finance.transacciones_ingreso OWNER TO postgres;

--
-- TOC entry 278 (class 1259 OID 28297)
-- Name: vista_balance_mensual; Type: VIEW; Schema: finance; Owner: postgres
--

CREATE VIEW finance.vista_balance_mensual AS
 SELECT EXTRACT(year FROM transacciones_ingreso.fecha_pago) AS anio,
    EXTRACT(month FROM transacciones_ingreso.fecha_pago) AS mes,
    'INGRESO'::text AS tipo_flujo,
    sum(transacciones_ingreso.monto) AS total_movimiento
   FROM finance.transacciones_ingreso
  GROUP BY (EXTRACT(year FROM transacciones_ingreso.fecha_pago)), (EXTRACT(month FROM transacciones_ingreso.fecha_pago))
UNION ALL
 SELECT EXTRACT(year FROM transacciones_egreso.fecha_pago) AS anio,
    EXTRACT(month FROM transacciones_egreso.fecha_pago) AS mes,
    'EGRESO'::text AS tipo_flujo,
    sum(transacciones_egreso.monto) AS total_movimiento
   FROM finance.transacciones_egreso
  GROUP BY (EXTRACT(year FROM transacciones_egreso.fecha_pago)), (EXTRACT(month FROM transacciones_egreso.fecha_pago));


ALTER VIEW finance.vista_balance_mensual OWNER TO postgres;

--
-- TOC entry 279 (class 1259 OID 28302)
-- Name: personas; Type: TABLE; Schema: people; Owner: postgres
--

CREATE TABLE people.personas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tipo character varying(50),
    cedula character varying(20),
    nombres character varying(100) NOT NULL,
    apellidos character varying(100) NOT NULL,
    correo character varying(150),
    celular character varying(20),
    ciudad_id bigint,
    cedula_photo_url character varying(500),
    es_activo boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    deleted_at timestamp with time zone
);


ALTER TABLE people.personas OWNER TO postgres;

--
-- TOC entry 280 (class 1259 OID 28311)
-- Name: vista_horas_instructor; Type: VIEW; Schema: finance; Owner: postgres
--

CREATE VIEW finance.vista_horas_instructor AS
 SELECT p.id AS instructor_id,
    (((p.nombres)::text || ' '::text) || (p.apellidos)::text) AS instructor,
    count(*) AS total_registros,
    sum(hi.horas_trabajadas) AS total_horas,
    sum(hi.monto_a_pagar) AS total_a_pagar,
    sum(hi.monto_a_pagar) FILTER (WHERE (hi.pagado = false)) AS pendiente_pago
   FROM (finance.horas_instructor hi
     JOIN people.personas p ON ((hi.instructor_id = p.id)))
  GROUP BY p.id, p.nombres, p.apellidos;


ALTER VIEW finance.vista_horas_instructor OWNER TO postgres;

--
-- TOC entry 281 (class 1259 OID 28316)
-- Name: vista_movimientos_caja; Type: VIEW; Schema: finance; Owner: postgres
--

CREATE VIEW finance.vista_movimientos_caja AS
 SELECT ef.id,
    ef.tipo_evento,
    ef.monto,
    ef.descripcion,
    ef.fecha_evento,
    ef.saldo_resultante,
    (((p.nombres)::text || ' '::text) || (p.apellidos)::text) AS registrado_por_nombre
   FROM (audit.eventos_financieros ef
     LEFT JOIN people.personas p ON ((ef.registrado_por = p.id)))
  ORDER BY ef.fecha_evento, ef.id;


ALTER VIEW finance.vista_movimientos_caja OWNER TO postgres;

--
-- TOC entry 282 (class 1259 OID 28321)
-- Name: registro_asistencia_staff; Type: TABLE; Schema: ops; Owner: postgres
--

CREATE TABLE ops.registro_asistencia_staff (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    fecha date NOT NULL,
    hora_entrada time without time zone,
    hora_salida time without time zone,
    actividades text,
    observaciones text,
    registrado_por uuid,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE ops.registro_asistencia_staff OWNER TO postgres;

--
-- TOC entry 283 (class 1259 OID 28328)
-- Name: clientes_externos; Type: TABLE; Schema: people; Owner: postgres
--

CREATE TABLE people.clientes_externos (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nombres character varying(100) NOT NULL,
    apellidos character varying(100),
    cedula character varying(20),
    correo character varying(150),
    celular character varying(20),
    ciudad_id bigint,
    observaciones text,
    created_at timestamp with time zone DEFAULT now(),
    ocupacion character varying(100),
    direccion text,
    estado_civil character varying(20),
    edad integer,
    fecha_nacimiento date,
    ciudad character varying(100)
);


ALTER TABLE people.clientes_externos OWNER TO postgres;

--
-- TOC entry 284 (class 1259 OID 28335)
-- Name: aulas; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.aulas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nombre character varying(100) NOT NULL,
    capacidad smallint NOT NULL,
    precio_hora numeric(10,2) NOT NULL,
    caracteristicas text
);


ALTER TABLE services.aulas OWNER TO postgres;

--
-- TOC entry 285 (class 1259 OID 28341)
-- Name: paquetes_podcast; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.paquetes_podcast (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    precio_base numeric(10,2) NOT NULL,
    es_activo boolean DEFAULT true
);


ALTER TABLE services.paquetes_podcast OWNER TO postgres;

--
-- TOC entry 286 (class 1259 OID 28347)
-- Name: reservas_aulas; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.reservas_aulas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    aula_id uuid NOT NULL,
    persona_id uuid,
    cliente_externo_id uuid,
    fecha_reserva date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    precio_total numeric(10,2) NOT NULL,
    estado services.t_estado_reserva DEFAULT 'reservado'::services.t_estado_reserva,
    CONSTRAINT chk_cliente_aula CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1))
);


ALTER TABLE services.reservas_aulas OWNER TO postgres;

--
-- TOC entry 287 (class 1259 OID 28353)
-- Name: reservas_podcast; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.reservas_podcast (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid,
    cliente_externo_id uuid,
    paquete_id integer NOT NULL,
    fecha_reserva date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    precio_total numeric(10,2) NOT NULL,
    observaciones text,
    estado services.t_estado_reserva DEFAULT 'reservado'::services.t_estado_reserva,
    CONSTRAINT chk_cliente_podcast CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1))
);


ALTER TABLE services.reservas_podcast OWNER TO postgres;

--
-- TOC entry 288 (class 1259 OID 28361)
-- Name: servicios_streaming; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.servicios_streaming (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid,
    cliente_externo_id uuid,
    fecha_evento date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    lugar character varying(300) NOT NULL,
    descripcion text,
    precio_total numeric(10,2) NOT NULL,
    estado services.t_estado_reserva DEFAULT 'reservado'::services.t_estado_reserva,
    created_at timestamp with time zone DEFAULT now(),
    CONSTRAINT chk_cliente_streaming CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1))
);


ALTER TABLE services.servicios_streaming OWNER TO postgres;

--
-- TOC entry 289 (class 1259 OID 28370)
-- Name: vista_agenda_unificada; Type: VIEW; Schema: ops; Owner: postgres
--

CREATE VIEW ops.vista_agenda_unificada AS
 SELECT 'CLASE_CURSO'::text AS tipo_evento,
    c.id AS referencia_id,
    ('Clase: '::text || (cc.nombre)::text) AS titulo,
    c.fecha_clase AS fecha,
    c.hora_inicio,
    c.hora_fin,
    (((p.nombres)::text || ' '::text) || (p.apellidos)::text) AS responsable
   FROM ((((academic.clases c
     JOIN academic.modulos m ON ((c.modulo_id = m.id)))
     JOIN academic.cursos_abiertos ca ON ((m.curso_abierto_id = ca.id)))
     JOIN academic.catalogo_cursos cc ON ((ca.catalogo_curso_id = cc.id)))
     LEFT JOIN people.personas p ON ((c.instructor_id = p.id)))
UNION ALL
 SELECT 'TALLER'::text AS tipo_evento,
    t.id AS referencia_id,
    ('Taller: '::text || (t.nombre)::text) AS titulo,
    t.fecha,
    t.hora_inicio,
    t.hora_fin,
    (((p.nombres)::text || ' '::text) || (p.apellidos)::text) AS responsable
   FROM (academic.talleres t
     LEFT JOIN people.personas p ON ((t.instructor_id = p.id)))
UNION ALL
 SELECT 'ALQUILER_AULA'::text AS tipo_evento,
    ra.id AS referencia_id,
    ('Aula: '::text || (a.nombre)::text) AS titulo,
    ra.fecha_reserva AS fecha,
    ra.hora_inicio,
    ra.hora_fin,
    COALESCE((((pp.nombres)::text || ' '::text) || (pp.apellidos)::text), (((ce.nombres)::text || ' '::text) || (COALESCE(ce.apellidos, ''::character varying))::text)) AS responsable
   FROM (((services.reservas_aulas ra
     JOIN services.aulas a ON ((ra.aula_id = a.id)))
     LEFT JOIN people.personas pp ON ((ra.persona_id = pp.id)))
     LEFT JOIN people.clientes_externos ce ON ((ra.cliente_externo_id = ce.id)))
UNION ALL
 SELECT 'PODCAST'::text AS tipo_evento,
    rp.id AS referencia_id,
    ('Podcast: '::text || (ppq.nombre)::text) AS titulo,
    rp.fecha_reserva AS fecha,
    rp.hora_inicio,
    rp.hora_fin,
    COALESCE((((pp.nombres)::text || ' '::text) || (pp.apellidos)::text), (((ce.nombres)::text || ' '::text) || (COALESCE(ce.apellidos, ''::character varying))::text)) AS responsable
   FROM (((services.reservas_podcast rp
     JOIN services.paquetes_podcast ppq ON ((rp.paquete_id = ppq.id)))
     LEFT JOIN people.personas pp ON ((rp.persona_id = pp.id)))
     LEFT JOIN people.clientes_externos ce ON ((rp.cliente_externo_id = ce.id)))
UNION ALL
 SELECT 'STREAMING'::text AS tipo_evento,
    ss.id AS referencia_id,
    ('Streaming: '::text || COALESCE(ss.descripcion, 'Servicio de streaming'::text)) AS titulo,
    ss.fecha_evento AS fecha,
    ss.hora_inicio,
    ss.hora_fin,
    COALESCE((((pp.nombres)::text || ' '::text) || (pp.apellidos)::text), (((ce.nombres)::text || ' '::text) || (COALESCE(ce.apellidos, ''::character varying))::text)) AS responsable
   FROM ((services.servicios_streaming ss
     LEFT JOIN people.personas pp ON ((ss.persona_id = pp.id)))
     LEFT JOIN people.clientes_externos ce ON ((ss.cliente_externo_id = ce.id)))
UNION ALL
 SELECT 'ASESORIA'::text AS tipo_evento,
    as2.id AS referencia_id,
    ('Asesoría: '::text || (as2.titulo)::text) AS titulo,
    as2.fecha,
    as2.hora_inicio,
    as2.hora_fin,
    (((pi.nombres)::text || ' '::text) || (pi.apellidos)::text) AS responsable
   FROM (academic.asesorias as2
     JOIN people.personas pi ON ((as2.instructor_id = pi.id)));


ALTER VIEW ops.vista_agenda_unificada OWNER TO postgres;

--
-- TOC entry 290 (class 1259 OID 28375)
-- Name: cuentas_sistema; Type: TABLE; Schema: people; Owner: postgres
--

CREATE TABLE people.cuentas_sistema (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    username character varying(100) NOT NULL,
    password_hash character varying(500) NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    last_login timestamp with time zone
);


ALTER TABLE people.cuentas_sistema OWNER TO postgres;

--
-- TOC entry 291 (class 1259 OID 28382)
-- Name: perfil_estudiante; Type: TABLE; Schema: people; Owner: postgres
--

CREATE TABLE people.perfil_estudiante (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    fecha_nacimiento date,
    notas_internas text,
    primera_matricula date,
    ultima_matricula date,
    total_cursos integer DEFAULT 0,
    ocupacion character varying(100),
    direccion text,
    estado_civil character varying(20),
    edad integer,
    ciudad character varying(100)
);


ALTER TABLE people.perfil_estudiante OWNER TO postgres;

--
-- TOC entry 292 (class 1259 OID 28389)
-- Name: perfil_instructor; Type: TABLE; Schema: people; Owner: postgres
--

CREATE TABLE people.perfil_instructor (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    especialidad character varying(200),
    bio text
);


ALTER TABLE people.perfil_instructor OWNER TO postgres;

--
-- TOC entry 293 (class 1259 OID 28395)
-- Name: perfil_staff; Type: TABLE; Schema: people; Owner: postgres
--

CREATE TABLE people.perfil_staff (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    cargo character varying(100) NOT NULL,
    salario_base numeric(10,2),
    fecha_ingreso date,
    es_pasante boolean DEFAULT false
);


ALTER TABLE people.perfil_staff OWNER TO postgres;

--
-- TOC entry 294 (class 1259 OID 28400)
-- Name: cache; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer
);


ALTER TABLE public.cache OWNER TO postgres;

--
-- TOC entry 295 (class 1259 OID 28405)
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer
);


ALTER TABLE public.cache_locks OWNER TO postgres;

--
-- TOC entry 296 (class 1259 OID 28410)
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.failed_jobs OWNER TO postgres;

--
-- TOC entry 297 (class 1259 OID 28416)
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO postgres;

--
-- TOC entry 5431 (class 0 OID 0)
-- Dependencies: 297
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- TOC entry 298 (class 1259 OID 28417)
-- Name: job_batches; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total integer NOT NULL,
    pending integer NOT NULL,
    failed integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO postgres;

--
-- TOC entry 299 (class 1259 OID 28422)
-- Name: jobs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO postgres;

--
-- TOC entry 300 (class 1259 OID 28427)
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO postgres;

--
-- TOC entry 5432 (class 0 OID 0)
-- Dependencies: 300
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- TOC entry 301 (class 1259 OID 28428)
-- Name: migrations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO postgres;

--
-- TOC entry 302 (class 1259 OID 28431)
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO postgres;

--
-- TOC entry 5433 (class 0 OID 0)
-- Dependencies: 302
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- TOC entry 303 (class 1259 OID 28432)
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp without time zone,
    expires_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


ALTER TABLE public.personal_access_tokens OWNER TO postgres;

--
-- TOC entry 304 (class 1259 OID 28437)
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.personal_access_tokens_id_seq OWNER TO postgres;

--
-- TOC entry 5434 (class 0 OID 0)
-- Dependencies: 304
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- TOC entry 305 (class 1259 OID 28438)
-- Name: sessions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO postgres;

--
-- TOC entry 306 (class 1259 OID 28443)
-- Name: alquiler_equipos; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.alquiler_equipos (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    equipo_id uuid NOT NULL,
    persona_id uuid,
    cliente_externo_id uuid,
    fecha_entrega timestamp(0) with time zone NOT NULL,
    fecha_devolucion_esperada timestamp(0) with time zone NOT NULL,
    fecha_recepcion timestamp(0) with time zone,
    foto_salida_url character varying(500),
    foto_retorno_url character varying(500),
    observaciones text,
    precio_total numeric(10,2) NOT NULL,
    estado character varying(20) DEFAULT 'activo'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT alquiler_equipos_cliente_check CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1)),
    CONSTRAINT alquiler_equipos_estado_check CHECK (((estado)::text = ANY (ARRAY[('activo'::character varying)::text, ('devuelto'::character varying)::text, ('vencido'::character varying)::text, ('pendiente'::character varying)::text, ('entregado'::character varying)::text])))
);


ALTER TABLE services.alquiler_equipos OWNER TO postgres;

--
-- TOC entry 307 (class 1259 OID 28452)
-- Name: asignaciones_personal; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.asignaciones_personal (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    reserva_podcast_id uuid,
    servicio_streaming_id uuid,
    servicio_produccion_id uuid,
    edicion_video_id uuid,
    rol_en_servicio character varying(100),
    reserva_radio_id uuid,
    CONSTRAINT chk_una_sola_asignacion CHECK ((num_nonnulls(reserva_podcast_id, servicio_streaming_id, servicio_produccion_id, edicion_video_id, reserva_radio_id) = 1))
);


ALTER TABLE services.asignaciones_personal OWNER TO postgres;

--
-- TOC entry 308 (class 1259 OID 28457)
-- Name: edicion_videos; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.edicion_videos (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid,
    cliente_externo_id uuid,
    fecha_recepcion date NOT NULL,
    fecha_entrega date NOT NULL,
    descripcion text,
    precio_total numeric(10,2) NOT NULL,
    estado services.t_estado_reserva DEFAULT 'reservado'::services.t_estado_reserva,
    created_at timestamp with time zone DEFAULT now(),
    CONSTRAINT chk_cliente_edicion CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1))
);


ALTER TABLE services.edicion_videos OWNER TO postgres;

--
-- TOC entry 309 (class 1259 OID 28466)
-- Name: equipos; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.equipos (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nombre character varying(200) NOT NULL,
    descripcion text,
    foto_url character varying(500),
    precio_diario numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    estado character varying(20) DEFAULT 'disponible'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT equipos_estado_check CHECK (((estado)::text = ANY (ARRAY[('disponible'::character varying)::text, ('alquilado'::character varying)::text, ('mantenimiento'::character varying)::text])))
);


ALTER TABLE services.equipos OWNER TO postgres;

--
-- TOC entry 310 (class 1259 OID 28475)
-- Name: items_paquete_podcast; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.items_paquete_podcast (
    id integer NOT NULL,
    paquete_id integer NOT NULL,
    descripcion character varying(200) NOT NULL
);


ALTER TABLE services.items_paquete_podcast OWNER TO postgres;

--
-- TOC entry 311 (class 1259 OID 28478)
-- Name: items_paquete_podcast_id_seq; Type: SEQUENCE; Schema: services; Owner: postgres
--

CREATE SEQUENCE services.items_paquete_podcast_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE services.items_paquete_podcast_id_seq OWNER TO postgres;

--
-- TOC entry 5435 (class 0 OID 0)
-- Dependencies: 311
-- Name: items_paquete_podcast_id_seq; Type: SEQUENCE OWNED BY; Schema: services; Owner: postgres
--

ALTER SEQUENCE services.items_paquete_podcast_id_seq OWNED BY services.items_paquete_podcast.id;


--
-- TOC entry 312 (class 1259 OID 28479)
-- Name: paquetes_podcast_id_seq; Type: SEQUENCE; Schema: services; Owner: postgres
--

CREATE SEQUENCE services.paquetes_podcast_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE services.paquetes_podcast_id_seq OWNER TO postgres;

--
-- TOC entry 5436 (class 0 OID 0)
-- Dependencies: 312
-- Name: paquetes_podcast_id_seq; Type: SEQUENCE OWNED BY; Schema: services; Owner: postgres
--

ALTER SEQUENCE services.paquetes_podcast_id_seq OWNED BY services.paquetes_podcast.id;


--
-- TOC entry 313 (class 1259 OID 28480)
-- Name: reservas_radio; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.reservas_radio (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tarifa_id bigint NOT NULL,
    persona_id uuid,
    cliente_externo_id uuid,
    fecha_reserva date NOT NULL,
    hora_inicio time(0) without time zone NOT NULL,
    hora_fin time(0) without time zone NOT NULL,
    incluye_operador boolean DEFAULT false NOT NULL,
    operador_id uuid,
    precio_total numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    observaciones text,
    estado character varying(20) DEFAULT 'reservado'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT reservas_radio_cliente_check CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1)),
    CONSTRAINT reservas_radio_estado_check CHECK (((estado)::text = ANY (ARRAY[('reservado'::character varying)::text, ('confirmado'::character varying)::text, ('en_progreso'::character varying)::text, ('completado'::character varying)::text, ('cancelado'::character varying)::text])))
);


ALTER TABLE services.reservas_radio OWNER TO postgres;

--
-- TOC entry 314 (class 1259 OID 28491)
-- Name: servicios_produccion; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.servicios_produccion (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid,
    cliente_externo_id uuid,
    fecha_evento date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    lugar character varying(300) NOT NULL,
    descripcion text,
    precio_total numeric(10,2) NOT NULL,
    estado services.t_estado_reserva DEFAULT 'reservado'::services.t_estado_reserva,
    created_at timestamp with time zone DEFAULT now(),
    CONSTRAINT chk_cliente_produccion CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1))
);


ALTER TABLE services.servicios_produccion OWNER TO postgres;

--
-- TOC entry 315 (class 1259 OID 28500)
-- Name: tarifas_radio; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.tarifas_radio (
    id bigint NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    precio_por_hora numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    incluye_operador boolean DEFAULT true NOT NULL,
    es_activo boolean DEFAULT true NOT NULL
);


ALTER TABLE services.tarifas_radio OWNER TO postgres;

--
-- TOC entry 316 (class 1259 OID 28508)
-- Name: tarifas_radio_id_seq; Type: SEQUENCE; Schema: services; Owner: postgres
--

CREATE SEQUENCE services.tarifas_radio_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE services.tarifas_radio_id_seq OWNER TO postgres;

--
-- TOC entry 5437 (class 0 OID 0)
-- Dependencies: 316
-- Name: tarifas_radio_id_seq; Type: SEQUENCE OWNED BY; Schema: services; Owner: postgres
--

ALTER SEQUENCE services.tarifas_radio_id_seq OWNED BY services.tarifas_radio.id;


--
-- TOC entry 317 (class 1259 OID 28509)
-- Name: trabajos_edicion; Type: TABLE; Schema: services; Owner: postgres
--

CREATE TABLE services.trabajos_edicion (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    titulo character varying(300) NOT NULL,
    descripcion text,
    fecha_recibo date NOT NULL,
    fecha_limite date NOT NULL,
    fecha_entrega date,
    nivel character varying(20) DEFAULT 'basica'::character varying NOT NULL,
    estado character varying(20) DEFAULT 'recibido'::character varying NOT NULL,
    editor_ids jsonb DEFAULT '[]'::jsonb NOT NULL,
    reserva_podcast_id uuid,
    precio_cobrado numeric(10,2),
    cobro_registrado boolean DEFAULT false NOT NULL,
    notas text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT trabajos_edicion_estado_check CHECK (((estado)::text = ANY (ARRAY[('recibido'::character varying)::text, ('en_proceso'::character varying)::text, ('revision'::character varying)::text, ('entregado'::character varying)::text]))),
    CONSTRAINT trabajos_edicion_nivel_check CHECK (((nivel)::text = ANY (ARRAY[('basica'::character varying)::text, ('estandar'::character varying)::text, ('premium'::character varying)::text])))
);


ALTER TABLE services.trabajos_edicion OWNER TO postgres;

--
-- TOC entry 4752 (class 2604 OID 28521)
-- Name: horarios_dias id; Type: DEFAULT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.horarios_dias ALTER COLUMN id SET DEFAULT nextval('academic.horarios_dias_id_seq'::regclass);


--
-- TOC entry 4780 (class 2604 OID 28522)
-- Name: ciudades id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.ciudades ALTER COLUMN id SET DEFAULT nextval('core.ciudades_id_seq'::regclass);


--
-- TOC entry 4781 (class 2604 OID 28523)
-- Name: failed_jobs id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.failed_jobs ALTER COLUMN id SET DEFAULT nextval('core.failed_jobs_id_seq'::regclass);


--
-- TOC entry 4783 (class 2604 OID 28524)
-- Name: jobs id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.jobs ALTER COLUMN id SET DEFAULT nextval('core.jobs_id_seq'::regclass);


--
-- TOC entry 4784 (class 2604 OID 28525)
-- Name: migrations id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.migrations ALTER COLUMN id SET DEFAULT nextval('core.migrations_id_seq'::regclass);


--
-- TOC entry 4785 (class 2604 OID 28526)
-- Name: permissions id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.permissions ALTER COLUMN id SET DEFAULT nextval('core.permissions_id_seq'::regclass);


--
-- TOC entry 4786 (class 2604 OID 28527)
-- Name: roles id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.roles ALTER COLUMN id SET DEFAULT nextval('core.roles_id_seq'::regclass);


--
-- TOC entry 4787 (class 2604 OID 28528)
-- Name: users id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.users ALTER COLUMN id SET DEFAULT nextval('core.users_id_seq'::regclass);


--
-- TOC entry 4788 (class 2604 OID 28529)
-- Name: categorias_egreso id; Type: DEFAULT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.categorias_egreso ALTER COLUMN id SET DEFAULT nextval('finance.categorias_egreso_id_seq'::regclass);


--
-- TOC entry 4834 (class 2604 OID 28530)
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- TOC entry 4836 (class 2604 OID 28531)
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- TOC entry 4837 (class 2604 OID 28532)
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- TOC entry 4838 (class 2604 OID 28533)
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- TOC entry 4848 (class 2604 OID 28534)
-- Name: items_paquete_podcast id; Type: DEFAULT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.items_paquete_podcast ALTER COLUMN id SET DEFAULT nextval('services.items_paquete_podcast_id_seq'::regclass);


--
-- TOC entry 4818 (class 2604 OID 28535)
-- Name: paquetes_podcast id; Type: DEFAULT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.paquetes_podcast ALTER COLUMN id SET DEFAULT nextval('services.paquetes_podcast_id_seq'::regclass);


--
-- TOC entry 4856 (class 2604 OID 28536)
-- Name: tarifas_radio id; Type: DEFAULT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.tarifas_radio ALTER COLUMN id SET DEFAULT nextval('services.tarifas_radio_id_seq'::regclass);


--
-- TOC entry 4908 (class 2606 OID 28538)
-- Name: asistencias_talleres academic_asistencias_talleres_taller_id_fecha_sesion_unique; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asistencias_talleres
    ADD CONSTRAINT academic_asistencias_talleres_taller_id_fecha_sesion_unique UNIQUE (taller_id, fecha_sesion);


--
-- TOC entry 4937 (class 2606 OID 28540)
-- Name: horarios_dias academic_horarios_dias_horario_id_dia_semana_unique; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.horarios_dias
    ADD CONSTRAINT academic_horarios_dias_horario_id_dia_semana_unique UNIQUE (horario_id, dia_semana);


--
-- TOC entry 4900 (class 2606 OID 28542)
-- Name: asesorias asesorias_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asesorias
    ADD CONSTRAINT asesorias_pkey PRIMARY KEY (id);


--
-- TOC entry 4902 (class 2606 OID 28544)
-- Name: asistencias asistencias_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asistencias
    ADD CONSTRAINT asistencias_pkey PRIMARY KEY (id);


--
-- TOC entry 4911 (class 2606 OID 28546)
-- Name: asistencias_talleres asistencias_talleres_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asistencias_talleres
    ADD CONSTRAINT asistencias_talleres_pkey PRIMARY KEY (id);


--
-- TOC entry 4913 (class 2606 OID 28548)
-- Name: cambios_horario cambios_horario_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cambios_horario
    ADD CONSTRAINT cambios_horario_pkey PRIMARY KEY (id);


--
-- TOC entry 4915 (class 2606 OID 28550)
-- Name: catalogo_cursos catalogo_cursos_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.catalogo_cursos
    ADD CONSTRAINT catalogo_cursos_pkey PRIMARY KEY (id);


--
-- TOC entry 4919 (class 2606 OID 28552)
-- Name: certificados certificados_codigo_certificado_key; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_codigo_certificado_key UNIQUE (codigo_certificado);


--
-- TOC entry 4921 (class 2606 OID 28554)
-- Name: certificados certificados_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_pkey PRIMARY KEY (id);


--
-- TOC entry 4926 (class 2606 OID 28556)
-- Name: clases_extras clases_extras_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.clases_extras
    ADD CONSTRAINT clases_extras_pkey PRIMARY KEY (id);


--
-- TOC entry 4923 (class 2606 OID 28558)
-- Name: clases clases_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.clases
    ADD CONSTRAINT clases_pkey PRIMARY KEY (id);


--
-- TOC entry 4928 (class 2606 OID 28560)
-- Name: comentarios_curso comentarios_curso_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.comentarios_curso
    ADD CONSTRAINT comentarios_curso_pkey PRIMARY KEY (id);


--
-- TOC entry 4930 (class 2606 OID 28562)
-- Name: cursos_abiertos cursos_abiertos_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_pkey PRIMARY KEY (id);


--
-- TOC entry 4940 (class 2606 OID 28564)
-- Name: horarios_dias horarios_dias_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.horarios_dias
    ADD CONSTRAINT horarios_dias_pkey PRIMARY KEY (id);


--
-- TOC entry 4934 (class 2606 OID 28566)
-- Name: horarios horarios_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.horarios
    ADD CONSTRAINT horarios_pkey PRIMARY KEY (id);


--
-- TOC entry 4942 (class 2606 OID 28568)
-- Name: inscripciones_taller inscripciones_taller_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.inscripciones_taller
    ADD CONSTRAINT inscripciones_taller_pkey PRIMARY KEY (id);


--
-- TOC entry 4946 (class 2606 OID 28570)
-- Name: matriculas matriculas_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT matriculas_pkey PRIMARY KEY (id);


--
-- TOC entry 4950 (class 2606 OID 28572)
-- Name: modulos modulos_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.modulos
    ADD CONSTRAINT modulos_pkey PRIMARY KEY (id);


--
-- TOC entry 4952 (class 2606 OID 28574)
-- Name: notas notas_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.notas
    ADD CONSTRAINT notas_pkey PRIMARY KEY (id);


--
-- TOC entry 4962 (class 2606 OID 28576)
-- Name: solicitudes_inscripcion solicitudes_inscripcion_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT solicitudes_inscripcion_pkey PRIMARY KEY (id);


--
-- TOC entry 4964 (class 2606 OID 28578)
-- Name: talleres talleres_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.talleres
    ADD CONSTRAINT talleres_pkey PRIMARY KEY (id);


--
-- TOC entry 4966 (class 2606 OID 28580)
-- Name: traslados_modulo traslados_modulo_pkey; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_pkey PRIMARY KEY (id);


--
-- TOC entry 4905 (class 2606 OID 28582)
-- Name: asistencias uq_asistencia; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asistencias
    ADD CONSTRAINT uq_asistencia UNIQUE (matricula_id, clase_id);


--
-- TOC entry 4948 (class 2606 OID 28584)
-- Name: matriculas uq_estudiante_curso; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT uq_estudiante_curso UNIQUE (estudiante_id, curso_abierto_id);


--
-- TOC entry 4954 (class 2606 OID 28586)
-- Name: notas uq_nota_modulo; Type: CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.notas
    ADD CONSTRAINT uq_nota_modulo UNIQUE (matricula_id, modulo_id);


--
-- TOC entry 4968 (class 2606 OID 28588)
-- Name: eventos_financieros eventos_financieros_pkey; Type: CONSTRAINT; Schema: audit; Owner: postgres
--

ALTER TABLE ONLY audit.eventos_financieros
    ADD CONSTRAINT eventos_financieros_pkey PRIMARY KEY (id);


--
-- TOC entry 4972 (class 2606 OID 28590)
-- Name: inicios_sesion inicios_sesion_pkey; Type: CONSTRAINT; Schema: audit; Owner: postgres
--

ALTER TABLE ONLY audit.inicios_sesion
    ADD CONSTRAINT inicios_sesion_pkey PRIMARY KEY (id);


--
-- TOC entry 4978 (class 2606 OID 28592)
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- TOC entry 4975 (class 2606 OID 28594)
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- TOC entry 4980 (class 2606 OID 28596)
-- Name: ciudades ciudades_nombre_key; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.ciudades
    ADD CONSTRAINT ciudades_nombre_key UNIQUE (nombre);


--
-- TOC entry 4982 (class 2606 OID 28598)
-- Name: ciudades ciudades_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.ciudades
    ADD CONSTRAINT ciudades_pkey PRIMARY KEY (id);


--
-- TOC entry 5006 (class 2606 OID 28600)
-- Name: permissions core_permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.permissions
    ADD CONSTRAINT core_permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- TOC entry 5012 (class 2606 OID 28602)
-- Name: roles core_roles_name_guard_name_unique; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.roles
    ADD CONSTRAINT core_roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- TOC entry 4984 (class 2606 OID 28604)
-- Name: estudiante_segmentos estudiante_segmentos_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.estudiante_segmentos
    ADD CONSTRAINT estudiante_segmentos_pkey PRIMARY KEY (id);


--
-- TOC entry 4987 (class 2606 OID 28606)
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- TOC entry 4989 (class 2606 OID 28608)
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- TOC entry 4991 (class 2606 OID 28610)
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- TOC entry 4993 (class 2606 OID 28612)
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- TOC entry 4996 (class 2606 OID 28614)
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- TOC entry 4999 (class 2606 OID 28616)
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- TOC entry 5002 (class 2606 OID 28618)
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- TOC entry 5004 (class 2606 OID 28620)
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- TOC entry 5008 (class 2606 OID 28622)
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- TOC entry 5010 (class 2606 OID 28624)
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- TOC entry 5014 (class 2606 OID 28626)
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- TOC entry 5017 (class 2606 OID 28628)
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- TOC entry 5020 (class 2606 OID 28630)
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- TOC entry 5022 (class 2606 OID 28632)
-- Name: users users_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- TOC entry 5024 (class 2606 OID 28634)
-- Name: categorias_egreso categorias_egreso_nombre_key; Type: CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.categorias_egreso
    ADD CONSTRAINT categorias_egreso_nombre_key UNIQUE (nombre);


--
-- TOC entry 5026 (class 2606 OID 28636)
-- Name: categorias_egreso categorias_egreso_pkey; Type: CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.categorias_egreso
    ADD CONSTRAINT categorias_egreso_pkey PRIMARY KEY (id);


--
-- TOC entry 5028 (class 2606 OID 28638)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_pkey; Type: CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_pkey PRIMARY KEY (id);


--
-- TOC entry 5036 (class 2606 OID 28640)
-- Name: horas_instructor horas_instructor_pkey; Type: CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_pkey PRIMARY KEY (id);


--
-- TOC entry 5145 (class 2606 OID 29355)
-- Name: lineas_pago_modulo lineas_pago_modulo_pkey; Type: CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.lineas_pago_modulo
    ADD CONSTRAINT lineas_pago_modulo_pkey PRIMARY KEY (id);


--
-- TOC entry 5039 (class 2606 OID 28642)
-- Name: resumen_caja resumen_caja_pkey; Type: CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.resumen_caja
    ADD CONSTRAINT resumen_caja_pkey PRIMARY KEY (id);


--
-- TOC entry 5042 (class 2606 OID 28644)
-- Name: transacciones_egreso transacciones_egreso_pkey; Type: CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.transacciones_egreso
    ADD CONSTRAINT transacciones_egreso_pkey PRIMARY KEY (id);


--
-- TOC entry 5045 (class 2606 OID 28646)
-- Name: transacciones_ingreso transacciones_ingreso_pkey; Type: CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.transacciones_ingreso
    ADD CONSTRAINT transacciones_ingreso_pkey PRIMARY KEY (id);


--
-- TOC entry 5056 (class 2606 OID 28648)
-- Name: registro_asistencia_staff registro_asistencia_staff_pkey; Type: CONSTRAINT; Schema: ops; Owner: postgres
--

ALTER TABLE ONLY ops.registro_asistencia_staff
    ADD CONSTRAINT registro_asistencia_staff_pkey PRIMARY KEY (id);


--
-- TOC entry 5058 (class 2606 OID 28650)
-- Name: registro_asistencia_staff uq_staff_dia; Type: CONSTRAINT; Schema: ops; Owner: postgres
--

ALTER TABLE ONLY ops.registro_asistencia_staff
    ADD CONSTRAINT uq_staff_dia UNIQUE (persona_id, fecha);


--
-- TOC entry 5060 (class 2606 OID 28652)
-- Name: clientes_externos clientes_externos_pkey; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.clientes_externos
    ADD CONSTRAINT clientes_externos_pkey PRIMARY KEY (id);


--
-- TOC entry 5079 (class 2606 OID 28654)
-- Name: cuentas_sistema cuentas_sistema_persona_id_key; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.cuentas_sistema
    ADD CONSTRAINT cuentas_sistema_persona_id_key UNIQUE (persona_id);


--
-- TOC entry 5081 (class 2606 OID 28656)
-- Name: cuentas_sistema cuentas_sistema_pkey; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.cuentas_sistema
    ADD CONSTRAINT cuentas_sistema_pkey PRIMARY KEY (id);


--
-- TOC entry 5083 (class 2606 OID 28658)
-- Name: cuentas_sistema cuentas_sistema_username_key; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.cuentas_sistema
    ADD CONSTRAINT cuentas_sistema_username_key UNIQUE (username);


--
-- TOC entry 5085 (class 2606 OID 28660)
-- Name: perfil_estudiante perfil_estudiante_persona_id_key; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_estudiante
    ADD CONSTRAINT perfil_estudiante_persona_id_key UNIQUE (persona_id);


--
-- TOC entry 5087 (class 2606 OID 28662)
-- Name: perfil_estudiante perfil_estudiante_pkey; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_estudiante
    ADD CONSTRAINT perfil_estudiante_pkey PRIMARY KEY (id);


--
-- TOC entry 5089 (class 2606 OID 28664)
-- Name: perfil_instructor perfil_instructor_persona_id_key; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_instructor
    ADD CONSTRAINT perfil_instructor_persona_id_key UNIQUE (persona_id);


--
-- TOC entry 5091 (class 2606 OID 28666)
-- Name: perfil_instructor perfil_instructor_pkey; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_instructor
    ADD CONSTRAINT perfil_instructor_pkey PRIMARY KEY (id);


--
-- TOC entry 5093 (class 2606 OID 28668)
-- Name: perfil_staff perfil_staff_persona_id_key; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_staff
    ADD CONSTRAINT perfil_staff_persona_id_key UNIQUE (persona_id);


--
-- TOC entry 5095 (class 2606 OID 28670)
-- Name: perfil_staff perfil_staff_pkey; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_staff
    ADD CONSTRAINT perfil_staff_pkey PRIMARY KEY (id);


--
-- TOC entry 5051 (class 2606 OID 28672)
-- Name: personas personas_cedula_key; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.personas
    ADD CONSTRAINT personas_cedula_key UNIQUE (cedula);


--
-- TOC entry 5053 (class 2606 OID 28674)
-- Name: personas personas_pkey; Type: CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.personas
    ADD CONSTRAINT personas_pkey PRIMARY KEY (id);


--
-- TOC entry 5099 (class 2606 OID 28676)
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- TOC entry 5097 (class 2606 OID 28678)
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- TOC entry 5101 (class 2606 OID 28680)
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- TOC entry 5103 (class 2606 OID 28682)
-- Name: failed_jobs failed_jobs_uuid_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_key UNIQUE (uuid);


--
-- TOC entry 5105 (class 2606 OID 28684)
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- TOC entry 5107 (class 2606 OID 28686)
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- TOC entry 5109 (class 2606 OID 28688)
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- TOC entry 5111 (class 2606 OID 28690)
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- TOC entry 5113 (class 2606 OID 28692)
-- Name: personal_access_tokens personal_access_tokens_token_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_key UNIQUE (token);


--
-- TOC entry 5115 (class 2606 OID 28694)
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- TOC entry 5117 (class 2606 OID 28696)
-- Name: alquiler_equipos alquiler_equipos_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.alquiler_equipos
    ADD CONSTRAINT alquiler_equipos_pkey PRIMARY KEY (id);


--
-- TOC entry 5121 (class 2606 OID 28698)
-- Name: asignaciones_personal asignaciones_personal_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_pkey PRIMARY KEY (id);


--
-- TOC entry 5065 (class 2606 OID 28700)
-- Name: aulas aulas_nombre_key; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.aulas
    ADD CONSTRAINT aulas_nombre_key UNIQUE (nombre);


--
-- TOC entry 5067 (class 2606 OID 28702)
-- Name: aulas aulas_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.aulas
    ADD CONSTRAINT aulas_pkey PRIMARY KEY (id);


--
-- TOC entry 5123 (class 2606 OID 28704)
-- Name: edicion_videos edicion_videos_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.edicion_videos
    ADD CONSTRAINT edicion_videos_pkey PRIMARY KEY (id);


--
-- TOC entry 5125 (class 2606 OID 28706)
-- Name: equipos equipos_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.equipos
    ADD CONSTRAINT equipos_pkey PRIMARY KEY (id);


--
-- TOC entry 5127 (class 2606 OID 28708)
-- Name: items_paquete_podcast items_paquete_podcast_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.items_paquete_podcast
    ADD CONSTRAINT items_paquete_podcast_pkey PRIMARY KEY (id);


--
-- TOC entry 5069 (class 2606 OID 28710)
-- Name: paquetes_podcast paquetes_podcast_nombre_key; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.paquetes_podcast
    ADD CONSTRAINT paquetes_podcast_nombre_key UNIQUE (nombre);


--
-- TOC entry 5071 (class 2606 OID 28712)
-- Name: paquetes_podcast paquetes_podcast_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.paquetes_podcast
    ADD CONSTRAINT paquetes_podcast_pkey PRIMARY KEY (id);


--
-- TOC entry 5073 (class 2606 OID 28714)
-- Name: reservas_aulas reservas_aulas_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_aulas
    ADD CONSTRAINT reservas_aulas_pkey PRIMARY KEY (id);


--
-- TOC entry 5075 (class 2606 OID 28716)
-- Name: reservas_podcast reservas_podcast_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_podcast
    ADD CONSTRAINT reservas_podcast_pkey PRIMARY KEY (id);


--
-- TOC entry 5129 (class 2606 OID 28718)
-- Name: reservas_radio reservas_radio_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT reservas_radio_pkey PRIMARY KEY (id);


--
-- TOC entry 5134 (class 2606 OID 28720)
-- Name: servicios_produccion servicios_produccion_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.servicios_produccion
    ADD CONSTRAINT servicios_produccion_pkey PRIMARY KEY (id);


--
-- TOC entry 5077 (class 2606 OID 28722)
-- Name: servicios_streaming servicios_streaming_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.servicios_streaming
    ADD CONSTRAINT servicios_streaming_pkey PRIMARY KEY (id);


--
-- TOC entry 5136 (class 2606 OID 28724)
-- Name: tarifas_radio tarifas_radio_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.tarifas_radio
    ADD CONSTRAINT tarifas_radio_pkey PRIMARY KEY (id);


--
-- TOC entry 5141 (class 2606 OID 28726)
-- Name: trabajos_edicion trabajos_edicion_pkey; Type: CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.trabajos_edicion
    ADD CONSTRAINT trabajos_edicion_pkey PRIMARY KEY (id);


--
-- TOC entry 4906 (class 1259 OID 28727)
-- Name: academic_asistencias_talleres_fecha_sesion_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_asistencias_talleres_fecha_sesion_index ON academic.asistencias_talleres USING btree (fecha_sesion);


--
-- TOC entry 4909 (class 1259 OID 28728)
-- Name: academic_asistencias_talleres_taller_id_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_asistencias_talleres_taller_id_index ON academic.asistencias_talleres USING btree (taller_id);


--
-- TOC entry 4916 (class 1259 OID 28729)
-- Name: academic_certificados_cedula_impresa_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_certificados_cedula_impresa_index ON academic.certificados USING btree (cedula_impresa);


--
-- TOC entry 4917 (class 1259 OID 28730)
-- Name: academic_certificados_estado_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_certificados_estado_index ON academic.certificados USING btree (estado);


--
-- TOC entry 4935 (class 1259 OID 28731)
-- Name: academic_horarios_dias_dia_semana_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_horarios_dias_dia_semana_index ON academic.horarios_dias USING btree (dia_semana);


--
-- TOC entry 4938 (class 1259 OID 28732)
-- Name: academic_horarios_dias_horario_id_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_horarios_dias_horario_id_index ON academic.horarios_dias USING btree (horario_id);


--
-- TOC entry 4955 (class 1259 OID 28733)
-- Name: academic_solicitudes_inscripcion_created_at_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_solicitudes_inscripcion_created_at_index ON academic.solicitudes_inscripcion USING btree (created_at);


--
-- TOC entry 4956 (class 1259 OID 28734)
-- Name: academic_solicitudes_inscripcion_curso_abierto_id_estado_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_solicitudes_inscripcion_curso_abierto_id_estado_index ON academic.solicitudes_inscripcion USING btree (curso_abierto_id, estado);


--
-- TOC entry 4957 (class 1259 OID 28735)
-- Name: academic_solicitudes_inscripcion_curso_abierto_id_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_solicitudes_inscripcion_curso_abierto_id_index ON academic.solicitudes_inscripcion USING btree (curso_abierto_id);


--
-- TOC entry 4958 (class 1259 OID 28736)
-- Name: academic_solicitudes_inscripcion_estado_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_solicitudes_inscripcion_estado_index ON academic.solicitudes_inscripcion USING btree (estado);


--
-- TOC entry 4959 (class 1259 OID 28737)
-- Name: academic_solicitudes_inscripcion_persona_id_estado_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_solicitudes_inscripcion_persona_id_estado_index ON academic.solicitudes_inscripcion USING btree (persona_id, estado);


--
-- TOC entry 4960 (class 1259 OID 28738)
-- Name: academic_solicitudes_inscripcion_persona_id_index; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX academic_solicitudes_inscripcion_persona_id_index ON academic.solicitudes_inscripcion USING btree (persona_id);


--
-- TOC entry 4903 (class 1259 OID 28739)
-- Name: idx_asistencias_clase; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX idx_asistencias_clase ON academic.asistencias USING btree (clase_id);


--
-- TOC entry 4924 (class 1259 OID 28740)
-- Name: idx_clases_fecha; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX idx_clases_fecha ON academic.clases USING btree (fecha_clase);


--
-- TOC entry 4931 (class 1259 OID 28741)
-- Name: idx_cursos_abiertos_resumen; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX idx_cursos_abiertos_resumen ON academic.cursos_abiertos USING btree (estudiantes_inscritos, ingreso_proyectado);


--
-- TOC entry 4932 (class 1259 OID 28742)
-- Name: idx_cursos_estado; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX idx_cursos_estado ON academic.cursos_abiertos USING btree (estado) WHERE (deleted_at IS NULL);


--
-- TOC entry 4943 (class 1259 OID 28743)
-- Name: idx_matriculas_curso; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX idx_matriculas_curso ON academic.matriculas USING btree (curso_abierto_id) WHERE (deleted_at IS NULL);


--
-- TOC entry 4944 (class 1259 OID 28744)
-- Name: idx_matriculas_estudiante; Type: INDEX; Schema: academic; Owner: postgres
--

CREATE INDEX idx_matriculas_estudiante ON academic.matriculas USING btree (estudiante_id) WHERE (deleted_at IS NULL);


--
-- TOC entry 4969 (class 1259 OID 28745)
-- Name: idx_audit_eventos_financieros_fecha; Type: INDEX; Schema: audit; Owner: postgres
--

CREATE INDEX idx_audit_eventos_financieros_fecha ON audit.eventos_financieros USING btree (fecha_evento DESC);


--
-- TOC entry 4970 (class 1259 OID 28746)
-- Name: idx_audit_inicios_sesion_fecha; Type: INDEX; Schema: audit; Owner: postgres
--

CREATE INDEX idx_audit_inicios_sesion_fecha ON audit.inicios_sesion USING btree (fecha_inicio DESC);


--
-- TOC entry 4973 (class 1259 OID 28747)
-- Name: cache_expiration_index; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX cache_expiration_index ON core.cache USING btree (expiration);


--
-- TOC entry 4976 (class 1259 OID 28748)
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX cache_locks_expiration_index ON core.cache_locks USING btree (expiration);


--
-- TOC entry 4985 (class 1259 OID 28749)
-- Name: failed_jobs_connection_queue_failed_at_index; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON core.failed_jobs USING btree (connection, queue, failed_at);


--
-- TOC entry 4994 (class 1259 OID 28750)
-- Name: jobs_queue_index; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX jobs_queue_index ON core.jobs USING btree (queue);


--
-- TOC entry 4997 (class 1259 OID 28751)
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON core.model_has_permissions USING btree (model_id, model_type);


--
-- TOC entry 5000 (class 1259 OID 28752)
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX model_has_roles_model_id_model_type_index ON core.model_has_roles USING btree (model_id, model_type);


--
-- TOC entry 5015 (class 1259 OID 28753)
-- Name: sessions_last_activity_index; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX sessions_last_activity_index ON core.sessions USING btree (last_activity);


--
-- TOC entry 5018 (class 1259 OID 28754)
-- Name: sessions_user_id_index; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX sessions_user_id_index ON core.sessions USING btree (user_id);


--
-- TOC entry 5029 (class 1259 OID 28755)
-- Name: finance_cuentas_por_cobrar_reserva_radio_id_index; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX finance_cuentas_por_cobrar_reserva_radio_id_index ON finance.cuentas_por_cobrar USING btree (reserva_radio_id);


--
-- TOC entry 5142 (class 1259 OID 29352)
-- Name: finance_lineas_pago_modulo_matricula_id_index; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX finance_lineas_pago_modulo_matricula_id_index ON finance.lineas_pago_modulo USING btree (matricula_id);


--
-- TOC entry 5143 (class 1259 OID 29353)
-- Name: finance_lineas_pago_modulo_modulo_id_index; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX finance_lineas_pago_modulo_modulo_id_index ON finance.lineas_pago_modulo USING btree (modulo_id);


--
-- TOC entry 5030 (class 1259 OID 28756)
-- Name: idx_cpc_matricula; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX idx_cpc_matricula ON finance.cuentas_por_cobrar USING btree (matricula_id) WHERE (matricula_id IS NOT NULL);


--
-- TOC entry 5031 (class 1259 OID 28757)
-- Name: idx_cpc_produccion; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX idx_cpc_produccion ON finance.cuentas_por_cobrar USING btree (servicio_produccion_id) WHERE (servicio_produccion_id IS NOT NULL);


--
-- TOC entry 5032 (class 1259 OID 28758)
-- Name: idx_cpc_reserva_aula; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX idx_cpc_reserva_aula ON finance.cuentas_por_cobrar USING btree (reserva_aula_id) WHERE (reserva_aula_id IS NOT NULL);


--
-- TOC entry 5033 (class 1259 OID 28759)
-- Name: idx_cpc_reserva_podcast; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX idx_cpc_reserva_podcast ON finance.cuentas_por_cobrar USING btree (reserva_podcast_id) WHERE (reserva_podcast_id IS NOT NULL);


--
-- TOC entry 5034 (class 1259 OID 28760)
-- Name: idx_cpc_streaming; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX idx_cpc_streaming ON finance.cuentas_por_cobrar USING btree (servicio_streaming_id) WHERE (servicio_streaming_id IS NOT NULL);


--
-- TOC entry 5040 (class 1259 OID 28761)
-- Name: idx_egresos_fecha; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX idx_egresos_fecha ON finance.transacciones_egreso USING btree (fecha_pago DESC);


--
-- TOC entry 5037 (class 1259 OID 28762)
-- Name: idx_horas_instructor_pago; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX idx_horas_instructor_pago ON finance.horas_instructor USING btree (instructor_id, pagado);


--
-- TOC entry 5043 (class 1259 OID 28763)
-- Name: idx_ingresos_fecha; Type: INDEX; Schema: finance; Owner: postgres
--

CREATE INDEX idx_ingresos_fecha ON finance.transacciones_ingreso USING btree (fecha_pago DESC);


--
-- TOC entry 5054 (class 1259 OID 28764)
-- Name: idx_staff_asistencia_fecha; Type: INDEX; Schema: ops; Owner: postgres
--

CREATE INDEX idx_staff_asistencia_fecha ON ops.registro_asistencia_staff USING btree (persona_id, fecha);


--
-- TOC entry 5061 (class 1259 OID 28765)
-- Name: idx_clientes_externos_apellidos; Type: INDEX; Schema: people; Owner: postgres
--

CREATE INDEX idx_clientes_externos_apellidos ON people.clientes_externos USING gin (apellidos public.gin_trgm_ops);


--
-- TOC entry 5062 (class 1259 OID 28766)
-- Name: idx_clientes_externos_cedula; Type: INDEX; Schema: people; Owner: postgres
--

CREATE INDEX idx_clientes_externos_cedula ON people.clientes_externos USING btree (cedula);


--
-- TOC entry 5063 (class 1259 OID 28767)
-- Name: idx_clientes_externos_nombres; Type: INDEX; Schema: people; Owner: postgres
--

CREATE INDEX idx_clientes_externos_nombres ON people.clientes_externos USING gin (nombres public.gin_trgm_ops);


--
-- TOC entry 5046 (class 1259 OID 28768)
-- Name: idx_personas_apellidos_trgm; Type: INDEX; Schema: people; Owner: postgres
--

CREATE INDEX idx_personas_apellidos_trgm ON people.personas USING gin (apellidos public.gin_trgm_ops);


--
-- TOC entry 5047 (class 1259 OID 28769)
-- Name: idx_personas_cedula; Type: INDEX; Schema: people; Owner: postgres
--

CREATE INDEX idx_personas_cedula ON people.personas USING btree (cedula) WHERE (deleted_at IS NULL);


--
-- TOC entry 5048 (class 1259 OID 28770)
-- Name: idx_personas_nombres_trgm; Type: INDEX; Schema: people; Owner: postgres
--

CREATE INDEX idx_personas_nombres_trgm ON people.personas USING gin (nombres public.gin_trgm_ops);


--
-- TOC entry 5049 (class 1259 OID 28771)
-- Name: idx_personas_tipo; Type: INDEX; Schema: people; Owner: postgres
--

CREATE INDEX idx_personas_tipo ON people.personas USING btree (tipo) WHERE (deleted_at IS NULL);


--
-- TOC entry 5118 (class 1259 OID 28772)
-- Name: services_alquiler_equipos_equipo_id_index; Type: INDEX; Schema: services; Owner: postgres
--

CREATE INDEX services_alquiler_equipos_equipo_id_index ON services.alquiler_equipos USING btree (equipo_id);


--
-- TOC entry 5119 (class 1259 OID 28773)
-- Name: services_alquiler_equipos_estado_index; Type: INDEX; Schema: services; Owner: postgres
--

CREATE INDEX services_alquiler_equipos_estado_index ON services.alquiler_equipos USING btree (estado);


--
-- TOC entry 5130 (class 1259 OID 28774)
-- Name: services_reservas_radio_estado_index; Type: INDEX; Schema: services; Owner: postgres
--

CREATE INDEX services_reservas_radio_estado_index ON services.reservas_radio USING btree (estado);


--
-- TOC entry 5131 (class 1259 OID 28775)
-- Name: services_reservas_radio_fecha_reserva_index; Type: INDEX; Schema: services; Owner: postgres
--

CREATE INDEX services_reservas_radio_fecha_reserva_index ON services.reservas_radio USING btree (fecha_reserva);


--
-- TOC entry 5132 (class 1259 OID 28776)
-- Name: services_reservas_radio_operador_id_index; Type: INDEX; Schema: services; Owner: postgres
--

CREATE INDEX services_reservas_radio_operador_id_index ON services.reservas_radio USING btree (operador_id);


--
-- TOC entry 5137 (class 1259 OID 28777)
-- Name: services_trabajos_edicion_estado_index; Type: INDEX; Schema: services; Owner: postgres
--

CREATE INDEX services_trabajos_edicion_estado_index ON services.trabajos_edicion USING btree (estado);


--
-- TOC entry 5138 (class 1259 OID 28778)
-- Name: services_trabajos_edicion_fecha_limite_index; Type: INDEX; Schema: services; Owner: postgres
--

CREATE INDEX services_trabajos_edicion_fecha_limite_index ON services.trabajos_edicion USING btree (fecha_limite);


--
-- TOC entry 5139 (class 1259 OID 28779)
-- Name: services_trabajos_edicion_fecha_recibo_index; Type: INDEX; Schema: services; Owner: postgres
--

CREATE INDEX services_trabajos_edicion_fecha_recibo_index ON services.trabajos_edicion USING btree (fecha_recibo);


--
-- TOC entry 5258 (class 2620 OID 28780)
-- Name: matriculas trg_actualizar_perfil_estudiante; Type: TRIGGER; Schema: academic; Owner: postgres
--

CREATE TRIGGER trg_actualizar_perfil_estudiante AFTER INSERT OR UPDATE ON academic.matriculas FOR EACH ROW EXECUTE FUNCTION academic.fn_actualizar_perfil_estudiante();

ALTER TABLE academic.matriculas DISABLE TRIGGER trg_actualizar_perfil_estudiante;


--
-- TOC entry 5259 (class 2620 OID 28781)
-- Name: matriculas trg_actualizar_resumen_curso; Type: TRIGGER; Schema: academic; Owner: postgres
--

CREATE TRIGGER trg_actualizar_resumen_curso AFTER INSERT OR DELETE OR UPDATE ON academic.matriculas FOR EACH ROW EXECUTE FUNCTION academic.fn_actualizar_resumen_curso();

ALTER TABLE academic.matriculas DISABLE TRIGGER trg_actualizar_resumen_curso;


--
-- TOC entry 5261 (class 2620 OID 28782)
-- Name: transacciones_ingreso trg_actualizar_saldo; Type: TRIGGER; Schema: finance; Owner: postgres
--

CREATE TRIGGER trg_actualizar_saldo AFTER INSERT OR DELETE OR UPDATE ON finance.transacciones_ingreso FOR EACH ROW EXECUTE FUNCTION finance.fn_actualizar_cuenta_cobrar();


--
-- TOC entry 5260 (class 2620 OID 28783)
-- Name: transacciones_egreso trg_resumen_caja_egreso; Type: TRIGGER; Schema: finance; Owner: postgres
--

CREATE TRIGGER trg_resumen_caja_egreso AFTER INSERT OR DELETE OR UPDATE ON finance.transacciones_egreso FOR EACH ROW EXECUTE FUNCTION finance.fn_registrar_movimiento_caja();


--
-- TOC entry 5262 (class 2620 OID 28784)
-- Name: transacciones_ingreso trg_resumen_caja_ingreso; Type: TRIGGER; Schema: finance; Owner: postgres
--

CREATE TRIGGER trg_resumen_caja_ingreso AFTER INSERT OR DELETE OR UPDATE ON finance.transacciones_ingreso FOR EACH ROW EXECUTE FUNCTION finance.fn_registrar_movimiento_caja();


--
-- TOC entry 5263 (class 2620 OID 28785)
-- Name: personas trg_personas_updated_at; Type: TRIGGER; Schema: people; Owner: postgres
--

CREATE TRIGGER trg_personas_updated_at BEFORE UPDATE ON people.personas FOR EACH ROW EXECUTE FUNCTION core.fn_set_updated_at();


--
-- TOC entry 5151 (class 2606 OID 28786)
-- Name: asistencias_talleres academic_asistencias_talleres_taller_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asistencias_talleres
    ADD CONSTRAINT academic_asistencias_talleres_taller_id_foreign FOREIGN KEY (taller_id) REFERENCES academic.talleres(id) ON DELETE CASCADE;


--
-- TOC entry 5173 (class 2606 OID 28791)
-- Name: matriculas academic_matriculas_solicitud_inscripcion_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT academic_matriculas_solicitud_inscripcion_id_foreign FOREIGN KEY (solicitud_inscripcion_id) REFERENCES academic.solicitudes_inscripcion(id) ON DELETE SET NULL;


--
-- TOC entry 5179 (class 2606 OID 28796)
-- Name: solicitudes_inscripcion academic_solicitudes_inscripcion_curso_abierto_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT academic_solicitudes_inscripcion_curso_abierto_id_foreign FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id) ON DELETE CASCADE;


--
-- TOC entry 5180 (class 2606 OID 28801)
-- Name: solicitudes_inscripcion academic_solicitudes_inscripcion_participante_externo_id_foreig; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT academic_solicitudes_inscripcion_participante_externo_id_foreig FOREIGN KEY (participante_externo_id) REFERENCES people.clientes_externos(id) ON DELETE CASCADE;


--
-- TOC entry 5181 (class 2606 OID 28806)
-- Name: solicitudes_inscripcion academic_solicitudes_inscripcion_persona_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT academic_solicitudes_inscripcion_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE CASCADE;


--
-- TOC entry 5182 (class 2606 OID 28811)
-- Name: solicitudes_inscripcion academic_solicitudes_inscripcion_validado_por_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT academic_solicitudes_inscripcion_validado_por_foreign FOREIGN KEY (validado_por) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5146 (class 2606 OID 28816)
-- Name: asesorias asesorias_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asesorias
    ADD CONSTRAINT asesorias_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5147 (class 2606 OID 28821)
-- Name: asesorias asesorias_instructor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asesorias
    ADD CONSTRAINT asesorias_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5148 (class 2606 OID 28826)
-- Name: asesorias asesorias_persona_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asesorias
    ADD CONSTRAINT asesorias_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5149 (class 2606 OID 28831)
-- Name: asistencias asistencias_clase_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asistencias
    ADD CONSTRAINT asistencias_clase_id_fkey FOREIGN KEY (clase_id) REFERENCES academic.clases(id) ON DELETE CASCADE;


--
-- TOC entry 5150 (class 2606 OID 28836)
-- Name: asistencias asistencias_matricula_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.asistencias
    ADD CONSTRAINT asistencias_matricula_id_fkey FOREIGN KEY (matricula_id) REFERENCES academic.matriculas(id) ON DELETE CASCADE;


--
-- TOC entry 5152 (class 2606 OID 28841)
-- Name: cambios_horario cambios_horario_autorizado_por_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cambios_horario
    ADD CONSTRAINT cambios_horario_autorizado_por_fkey FOREIGN KEY (autorizado_por) REFERENCES people.personas(id);


--
-- TOC entry 5153 (class 2606 OID 28846)
-- Name: cambios_horario cambios_horario_curso_abierto_nuevo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cambios_horario
    ADD CONSTRAINT cambios_horario_curso_abierto_nuevo_id_fkey FOREIGN KEY (curso_abierto_nuevo_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5154 (class 2606 OID 28851)
-- Name: cambios_horario cambios_horario_matricula_origen_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cambios_horario
    ADD CONSTRAINT cambios_horario_matricula_origen_id_fkey FOREIGN KEY (matricula_origen_id) REFERENCES academic.matriculas(id);


--
-- TOC entry 5155 (class 2606 OID 28856)
-- Name: certificados certificados_catalogo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_catalogo_id_fkey FOREIGN KEY (catalogo_id) REFERENCES academic.catalogo_cursos(id);


--
-- TOC entry 5156 (class 2606 OID 28861)
-- Name: certificados certificados_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5157 (class 2606 OID 28866)
-- Name: certificados certificados_estudiante_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES people.personas(id);


--
-- TOC entry 5158 (class 2606 OID 28871)
-- Name: certificados certificados_modulo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_modulo_id_fkey FOREIGN KEY (modulo_id) REFERENCES academic.modulos(id);


--
-- TOC entry 5161 (class 2606 OID 28876)
-- Name: clases_extras clases_extras_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.clases_extras
    ADD CONSTRAINT clases_extras_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5162 (class 2606 OID 28881)
-- Name: clases_extras clases_extras_estudiante_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.clases_extras
    ADD CONSTRAINT clases_extras_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES people.personas(id);


--
-- TOC entry 5163 (class 2606 OID 28886)
-- Name: clases_extras clases_extras_instructor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.clases_extras
    ADD CONSTRAINT clases_extras_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5159 (class 2606 OID 28891)
-- Name: clases clases_instructor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.clases
    ADD CONSTRAINT clases_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5160 (class 2606 OID 28896)
-- Name: clases clases_modulo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.clases
    ADD CONSTRAINT clases_modulo_id_fkey FOREIGN KEY (modulo_id) REFERENCES academic.modulos(id) ON DELETE CASCADE;


--
-- TOC entry 5164 (class 2606 OID 28901)
-- Name: comentarios_curso comentarios_curso_autor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.comentarios_curso
    ADD CONSTRAINT comentarios_curso_autor_id_fkey FOREIGN KEY (autor_id) REFERENCES people.personas(id);


--
-- TOC entry 5165 (class 2606 OID 28906)
-- Name: comentarios_curso comentarios_curso_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.comentarios_curso
    ADD CONSTRAINT comentarios_curso_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5166 (class 2606 OID 28911)
-- Name: cursos_abiertos cursos_abiertos_catalogo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_catalogo_id_fkey FOREIGN KEY (catalogo_curso_id) REFERENCES academic.catalogo_cursos(id);


--
-- TOC entry 5167 (class 2606 OID 28916)
-- Name: cursos_abiertos cursos_abiertos_ciudad_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_ciudad_id_fkey FOREIGN KEY (ciudad_id) REFERENCES core.ciudades(id);


--
-- TOC entry 5168 (class 2606 OID 28921)
-- Name: cursos_abiertos cursos_abiertos_docente_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_docente_id_fkey FOREIGN KEY (docente_id) REFERENCES people.personas(id);


--
-- TOC entry 5169 (class 2606 OID 28926)
-- Name: cursos_abiertos cursos_abiertos_horario_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_horario_id_fkey FOREIGN KEY (horario_id) REFERENCES academic.horarios(id);


--
-- TOC entry 5170 (class 2606 OID 28931)
-- Name: cursos_abiertos cursos_abiertos_instructor_titular_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_instructor_titular_id_fkey FOREIGN KEY (instructor_titular_id) REFERENCES people.personas(id);


--
-- TOC entry 5171 (class 2606 OID 28936)
-- Name: inscripciones_taller inscripciones_taller_persona_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.inscripciones_taller
    ADD CONSTRAINT inscripciones_taller_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5172 (class 2606 OID 28941)
-- Name: inscripciones_taller inscripciones_taller_taller_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.inscripciones_taller
    ADD CONSTRAINT inscripciones_taller_taller_id_fkey FOREIGN KEY (taller_id) REFERENCES academic.talleres(id);


--
-- TOC entry 5174 (class 2606 OID 28946)
-- Name: matriculas matriculas_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT matriculas_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5175 (class 2606 OID 28951)
-- Name: matriculas matriculas_estudiante_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT matriculas_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES people.personas(id);


--
-- TOC entry 5176 (class 2606 OID 28956)
-- Name: modulos modulos_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.modulos
    ADD CONSTRAINT modulos_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id) ON DELETE CASCADE;


--
-- TOC entry 5177 (class 2606 OID 28961)
-- Name: notas notas_matricula_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.notas
    ADD CONSTRAINT notas_matricula_id_fkey FOREIGN KEY (matricula_id) REFERENCES academic.matriculas(id) ON DELETE CASCADE;


--
-- TOC entry 5178 (class 2606 OID 28966)
-- Name: notas notas_modulo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.notas
    ADD CONSTRAINT notas_modulo_id_fkey FOREIGN KEY (modulo_id) REFERENCES academic.modulos(id);


--
-- TOC entry 5183 (class 2606 OID 28971)
-- Name: talleres talleres_ciudad_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.talleres
    ADD CONSTRAINT talleres_ciudad_id_fkey FOREIGN KEY (ciudad_id) REFERENCES core.ciudades(id);


--
-- TOC entry 5184 (class 2606 OID 28976)
-- Name: talleres talleres_instructor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.talleres
    ADD CONSTRAINT talleres_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5185 (class 2606 OID 28981)
-- Name: traslados_modulo traslados_modulo_autorizado_por_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_autorizado_por_fkey FOREIGN KEY (autorizado_por) REFERENCES people.personas(id);


--
-- TOC entry 5186 (class 2606 OID 28986)
-- Name: traslados_modulo traslados_modulo_curso_abierto_destino_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_curso_abierto_destino_id_fkey FOREIGN KEY (curso_abierto_destino_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5187 (class 2606 OID 28991)
-- Name: traslados_modulo traslados_modulo_matricula_origen_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_matricula_origen_id_fkey FOREIGN KEY (matricula_origen_id) REFERENCES academic.matriculas(id);


--
-- TOC entry 5188 (class 2606 OID 28996)
-- Name: traslados_modulo traslados_modulo_modulo_destino_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_modulo_destino_id_fkey FOREIGN KEY (modulo_destino_id) REFERENCES academic.modulos(id);


--
-- TOC entry 5189 (class 2606 OID 29001)
-- Name: traslados_modulo traslados_modulo_modulo_origen_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: postgres
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_modulo_origen_id_fkey FOREIGN KEY (modulo_origen_id) REFERENCES academic.modulos(id);


--
-- TOC entry 5190 (class 2606 OID 29006)
-- Name: eventos_financieros eventos_financieros_registrado_por_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: postgres
--

ALTER TABLE ONLY audit.eventos_financieros
    ADD CONSTRAINT eventos_financieros_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES people.personas(id);


--
-- TOC entry 5191 (class 2606 OID 29011)
-- Name: eventos_financieros eventos_financieros_transaccion_egreso_id_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: postgres
--

ALTER TABLE ONLY audit.eventos_financieros
    ADD CONSTRAINT eventos_financieros_transaccion_egreso_id_fkey FOREIGN KEY (transaccion_egreso_id) REFERENCES finance.transacciones_egreso(id) ON DELETE CASCADE;


--
-- TOC entry 5192 (class 2606 OID 29016)
-- Name: eventos_financieros eventos_financieros_transaccion_ingreso_id_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: postgres
--

ALTER TABLE ONLY audit.eventos_financieros
    ADD CONSTRAINT eventos_financieros_transaccion_ingreso_id_fkey FOREIGN KEY (transaccion_ingreso_id) REFERENCES finance.transacciones_ingreso(id) ON DELETE CASCADE;


--
-- TOC entry 5193 (class 2606 OID 29021)
-- Name: inicios_sesion inicios_sesion_cuenta_id_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: postgres
--

ALTER TABLE ONLY audit.inicios_sesion
    ADD CONSTRAINT inicios_sesion_cuenta_id_fkey FOREIGN KEY (cuenta_id) REFERENCES people.cuentas_sistema(id) ON DELETE SET NULL;


--
-- TOC entry 5194 (class 2606 OID 29026)
-- Name: inicios_sesion inicios_sesion_persona_id_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: postgres
--

ALTER TABLE ONLY audit.inicios_sesion
    ADD CONSTRAINT inicios_sesion_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5195 (class 2606 OID 29031)
-- Name: model_has_permissions core_model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.model_has_permissions
    ADD CONSTRAINT core_model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES core.permissions(id) ON DELETE CASCADE;


--
-- TOC entry 5196 (class 2606 OID 29036)
-- Name: model_has_roles core_model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.model_has_roles
    ADD CONSTRAINT core_model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES core.roles(id) ON DELETE CASCADE;


--
-- TOC entry 5197 (class 2606 OID 29041)
-- Name: role_has_permissions core_role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.role_has_permissions
    ADD CONSTRAINT core_role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES core.permissions(id) ON DELETE CASCADE;


--
-- TOC entry 5198 (class 2606 OID 29046)
-- Name: role_has_permissions core_role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.role_has_permissions
    ADD CONSTRAINT core_role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES core.roles(id) ON DELETE CASCADE;


--
-- TOC entry 5199 (class 2606 OID 29051)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_asesoria_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_asesoria_id_fkey FOREIGN KEY (asesoria_id) REFERENCES academic.asesorias(id);


--
-- TOC entry 5200 (class 2606 OID 29056)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_clase_extra_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_clase_extra_id_fkey FOREIGN KEY (clase_extra_id) REFERENCES academic.clases_extras(id);


--
-- TOC entry 5201 (class 2606 OID 29061)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_edicion_video_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_edicion_video_id_fkey FOREIGN KEY (edicion_video_id) REFERENCES services.edicion_videos(id);


--
-- TOC entry 5202 (class 2606 OID 29066)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_inscripcion_taller_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_inscripcion_taller_id_fkey FOREIGN KEY (inscripcion_taller_id) REFERENCES academic.inscripciones_taller(id);


--
-- TOC entry 5203 (class 2606 OID 29071)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_matricula_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_matricula_id_fkey FOREIGN KEY (matricula_id) REFERENCES academic.matriculas(id);


--
-- TOC entry 5204 (class 2606 OID 29076)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_reserva_aula_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_reserva_aula_id_fkey FOREIGN KEY (reserva_aula_id) REFERENCES services.reservas_aulas(id);


--
-- TOC entry 5205 (class 2606 OID 29081)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_reserva_podcast_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_reserva_podcast_id_fkey FOREIGN KEY (reserva_podcast_id) REFERENCES services.reservas_podcast(id);


--
-- TOC entry 5206 (class 2606 OID 29086)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_servicio_produccion_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_servicio_produccion_id_fkey FOREIGN KEY (servicio_produccion_id) REFERENCES services.servicios_produccion(id);


--
-- TOC entry 5207 (class 2606 OID 29091)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_servicio_streaming_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_servicio_streaming_id_fkey FOREIGN KEY (servicio_streaming_id) REFERENCES services.servicios_streaming(id);


--
-- TOC entry 5208 (class 2606 OID 29096)
-- Name: cuentas_por_cobrar finance_cuentas_por_cobrar_alquiler_equipo_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT finance_cuentas_por_cobrar_alquiler_equipo_id_foreign FOREIGN KEY (alquiler_equipo_id) REFERENCES services.alquiler_equipos(id) ON DELETE SET NULL;


--
-- TOC entry 5209 (class 2606 OID 29101)
-- Name: cuentas_por_cobrar finance_cuentas_por_cobrar_reserva_radio_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT finance_cuentas_por_cobrar_reserva_radio_id_foreign FOREIGN KEY (reserva_radio_id) REFERENCES services.reservas_radio(id) ON DELETE SET NULL;


--
-- TOC entry 5210 (class 2606 OID 29106)
-- Name: cuentas_por_cobrar finance_cuentas_por_cobrar_solicitud_inscripcion_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT finance_cuentas_por_cobrar_solicitud_inscripcion_id_foreign FOREIGN KEY (solicitud_inscripcion_id) REFERENCES academic.solicitudes_inscripcion(id) ON DELETE SET NULL;


--
-- TOC entry 5255 (class 2606 OID 29347)
-- Name: lineas_pago_modulo finance_lineas_pago_modulo_ajustado_por_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.lineas_pago_modulo
    ADD CONSTRAINT finance_lineas_pago_modulo_ajustado_por_foreign FOREIGN KEY (ajustado_por) REFERENCES people.personas(id);


--
-- TOC entry 5256 (class 2606 OID 29337)
-- Name: lineas_pago_modulo finance_lineas_pago_modulo_matricula_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.lineas_pago_modulo
    ADD CONSTRAINT finance_lineas_pago_modulo_matricula_id_foreign FOREIGN KEY (matricula_id) REFERENCES academic.matriculas(id) ON DELETE CASCADE;


--
-- TOC entry 5257 (class 2606 OID 29342)
-- Name: lineas_pago_modulo finance_lineas_pago_modulo_modulo_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.lineas_pago_modulo
    ADD CONSTRAINT finance_lineas_pago_modulo_modulo_id_foreign FOREIGN KEY (modulo_id) REFERENCES academic.modulos(id) ON DELETE RESTRICT;


--
-- TOC entry 5217 (class 2606 OID 29356)
-- Name: transacciones_ingreso finance_transacciones_ingreso_linea_pago_modulo_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.transacciones_ingreso
    ADD CONSTRAINT finance_transacciones_ingreso_linea_pago_modulo_id_foreign FOREIGN KEY (linea_pago_modulo_id) REFERENCES finance.lineas_pago_modulo(id);


--
-- TOC entry 5211 (class 2606 OID 29111)
-- Name: horas_instructor horas_instructor_clase_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_clase_id_fkey FOREIGN KEY (clase_id) REFERENCES academic.clases(id);


--
-- TOC entry 5212 (class 2606 OID 29116)
-- Name: horas_instructor horas_instructor_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5213 (class 2606 OID 29121)
-- Name: horas_instructor horas_instructor_egreso_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_egreso_id_fkey FOREIGN KEY (egreso_id) REFERENCES finance.transacciones_egreso(id);


--
-- TOC entry 5214 (class 2606 OID 29126)
-- Name: horas_instructor horas_instructor_instructor_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5215 (class 2606 OID 29131)
-- Name: transacciones_egreso transacciones_egreso_categoria_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.transacciones_egreso
    ADD CONSTRAINT transacciones_egreso_categoria_id_fkey FOREIGN KEY (categoria_id) REFERENCES finance.categorias_egreso(id);


--
-- TOC entry 5216 (class 2606 OID 29136)
-- Name: transacciones_egreso transacciones_egreso_registrado_por_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.transacciones_egreso
    ADD CONSTRAINT transacciones_egreso_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES people.personas(id);


--
-- TOC entry 5218 (class 2606 OID 29141)
-- Name: transacciones_ingreso transacciones_ingreso_cuenta_cobrar_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.transacciones_ingreso
    ADD CONSTRAINT transacciones_ingreso_cuenta_cobrar_id_fkey FOREIGN KEY (cuenta_cobrar_id) REFERENCES finance.cuentas_por_cobrar(id) ON DELETE RESTRICT;


--
-- TOC entry 5219 (class 2606 OID 29146)
-- Name: transacciones_ingreso transacciones_ingreso_registrado_por_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: postgres
--

ALTER TABLE ONLY finance.transacciones_ingreso
    ADD CONSTRAINT transacciones_ingreso_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES people.personas(id);


--
-- TOC entry 5221 (class 2606 OID 29151)
-- Name: registro_asistencia_staff registro_asistencia_staff_persona_id_fkey; Type: FK CONSTRAINT; Schema: ops; Owner: postgres
--

ALTER TABLE ONLY ops.registro_asistencia_staff
    ADD CONSTRAINT registro_asistencia_staff_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5222 (class 2606 OID 29156)
-- Name: registro_asistencia_staff registro_asistencia_staff_registrado_por_fkey; Type: FK CONSTRAINT; Schema: ops; Owner: postgres
--

ALTER TABLE ONLY ops.registro_asistencia_staff
    ADD CONSTRAINT registro_asistencia_staff_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES people.personas(id);


--
-- TOC entry 5223 (class 2606 OID 29161)
-- Name: clientes_externos clientes_externos_ciudad_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.clientes_externos
    ADD CONSTRAINT clientes_externos_ciudad_id_fkey FOREIGN KEY (ciudad_id) REFERENCES core.ciudades(id);


--
-- TOC entry 5232 (class 2606 OID 29166)
-- Name: cuentas_sistema cuentas_sistema_persona_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.cuentas_sistema
    ADD CONSTRAINT cuentas_sistema_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5233 (class 2606 OID 29171)
-- Name: perfil_estudiante perfil_estudiante_persona_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_estudiante
    ADD CONSTRAINT perfil_estudiante_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5234 (class 2606 OID 29176)
-- Name: perfil_instructor perfil_instructor_persona_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_instructor
    ADD CONSTRAINT perfil_instructor_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5235 (class 2606 OID 29181)
-- Name: perfil_staff perfil_staff_persona_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.perfil_staff
    ADD CONSTRAINT perfil_staff_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5220 (class 2606 OID 29186)
-- Name: personas personas_ciudad_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: postgres
--

ALTER TABLE ONLY people.personas
    ADD CONSTRAINT personas_ciudad_id_fkey FOREIGN KEY (ciudad_id) REFERENCES core.ciudades(id);


--
-- TOC entry 5239 (class 2606 OID 29191)
-- Name: asignaciones_personal asignaciones_personal_edicion_video_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_edicion_video_id_fkey FOREIGN KEY (edicion_video_id) REFERENCES services.edicion_videos(id);


--
-- TOC entry 5240 (class 2606 OID 29196)
-- Name: asignaciones_personal asignaciones_personal_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5241 (class 2606 OID 29201)
-- Name: asignaciones_personal asignaciones_personal_reserva_podcast_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_reserva_podcast_id_fkey FOREIGN KEY (reserva_podcast_id) REFERENCES services.reservas_podcast(id);


--
-- TOC entry 5242 (class 2606 OID 29206)
-- Name: asignaciones_personal asignaciones_personal_servicio_produccion_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_servicio_produccion_id_fkey FOREIGN KEY (servicio_produccion_id) REFERENCES services.servicios_produccion(id);


--
-- TOC entry 5243 (class 2606 OID 29211)
-- Name: asignaciones_personal asignaciones_personal_servicio_streaming_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_servicio_streaming_id_fkey FOREIGN KEY (servicio_streaming_id) REFERENCES services.servicios_streaming(id);


--
-- TOC entry 5245 (class 2606 OID 29216)
-- Name: edicion_videos edicion_videos_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.edicion_videos
    ADD CONSTRAINT edicion_videos_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5246 (class 2606 OID 29221)
-- Name: edicion_videos edicion_videos_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.edicion_videos
    ADD CONSTRAINT edicion_videos_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5247 (class 2606 OID 29226)
-- Name: items_paquete_podcast items_paquete_podcast_paquete_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.items_paquete_podcast
    ADD CONSTRAINT items_paquete_podcast_paquete_id_fkey FOREIGN KEY (paquete_id) REFERENCES services.paquetes_podcast(id);


--
-- TOC entry 5224 (class 2606 OID 29231)
-- Name: reservas_aulas reservas_aulas_aula_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_aulas
    ADD CONSTRAINT reservas_aulas_aula_id_fkey FOREIGN KEY (aula_id) REFERENCES services.aulas(id);


--
-- TOC entry 5225 (class 2606 OID 29236)
-- Name: reservas_aulas reservas_aulas_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_aulas
    ADD CONSTRAINT reservas_aulas_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5226 (class 2606 OID 29241)
-- Name: reservas_aulas reservas_aulas_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_aulas
    ADD CONSTRAINT reservas_aulas_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5227 (class 2606 OID 29246)
-- Name: reservas_podcast reservas_podcast_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_podcast
    ADD CONSTRAINT reservas_podcast_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5228 (class 2606 OID 29251)
-- Name: reservas_podcast reservas_podcast_paquete_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_podcast
    ADD CONSTRAINT reservas_podcast_paquete_id_fkey FOREIGN KEY (paquete_id) REFERENCES services.paquetes_podcast(id);


--
-- TOC entry 5229 (class 2606 OID 29256)
-- Name: reservas_podcast reservas_podcast_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_podcast
    ADD CONSTRAINT reservas_podcast_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5236 (class 2606 OID 29261)
-- Name: alquiler_equipos services_alquiler_equipos_cliente_externo_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.alquiler_equipos
    ADD CONSTRAINT services_alquiler_equipos_cliente_externo_id_foreign FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id) ON DELETE SET NULL;


--
-- TOC entry 5237 (class 2606 OID 29266)
-- Name: alquiler_equipos services_alquiler_equipos_equipo_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.alquiler_equipos
    ADD CONSTRAINT services_alquiler_equipos_equipo_id_foreign FOREIGN KEY (equipo_id) REFERENCES services.equipos(id);


--
-- TOC entry 5238 (class 2606 OID 29271)
-- Name: alquiler_equipos services_alquiler_equipos_persona_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.alquiler_equipos
    ADD CONSTRAINT services_alquiler_equipos_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5244 (class 2606 OID 29276)
-- Name: asignaciones_personal services_asignaciones_personal_reserva_radio_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT services_asignaciones_personal_reserva_radio_id_foreign FOREIGN KEY (reserva_radio_id) REFERENCES services.reservas_radio(id) ON DELETE CASCADE;


--
-- TOC entry 5248 (class 2606 OID 29281)
-- Name: reservas_radio services_reservas_radio_cliente_externo_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT services_reservas_radio_cliente_externo_id_foreign FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id) ON DELETE SET NULL;


--
-- TOC entry 5249 (class 2606 OID 29286)
-- Name: reservas_radio services_reservas_radio_operador_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT services_reservas_radio_operador_id_foreign FOREIGN KEY (operador_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5250 (class 2606 OID 29291)
-- Name: reservas_radio services_reservas_radio_persona_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT services_reservas_radio_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5251 (class 2606 OID 29296)
-- Name: reservas_radio services_reservas_radio_tarifa_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT services_reservas_radio_tarifa_id_foreign FOREIGN KEY (tarifa_id) REFERENCES services.tarifas_radio(id);


--
-- TOC entry 5254 (class 2606 OID 29301)
-- Name: trabajos_edicion services_trabajos_edicion_reserva_podcast_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.trabajos_edicion
    ADD CONSTRAINT services_trabajos_edicion_reserva_podcast_id_foreign FOREIGN KEY (reserva_podcast_id) REFERENCES services.reservas_podcast(id) ON DELETE SET NULL;


--
-- TOC entry 5252 (class 2606 OID 29306)
-- Name: servicios_produccion servicios_produccion_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.servicios_produccion
    ADD CONSTRAINT servicios_produccion_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5253 (class 2606 OID 29311)
-- Name: servicios_produccion servicios_produccion_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.servicios_produccion
    ADD CONSTRAINT servicios_produccion_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5230 (class 2606 OID 29316)
-- Name: servicios_streaming servicios_streaming_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.servicios_streaming
    ADD CONSTRAINT servicios_streaming_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5231 (class 2606 OID 29321)
-- Name: servicios_streaming servicios_streaming_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: postgres
--

ALTER TABLE ONLY services.servicios_streaming
    ADD CONSTRAINT servicios_streaming_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


-- Completed on 2026-06-21 17:11:11 -05

--
-- PostgreSQL database dump complete
--



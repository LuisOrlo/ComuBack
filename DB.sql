--
-- PostgreSQL database dump
--

\restrict 9dDCa2NhvQqvcQooWp1qVWwLKWuaCbojFTA4VVtP1gM3dWJPBXyPj68u1iDt2jB

-- Dumped from database version 16.13
-- Dumped by pg_dump version 16.13

-- Started on 2026-07-17 15:31:49 -05

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
-- TOC entry 9 (class 2615 OID 37939)
-- Name: academic; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA academic;


--
-- TOC entry 10 (class 2615 OID 37940)
-- Name: audit; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA audit;


--
-- TOC entry 11 (class 2615 OID 37941)
-- Name: core; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA core;


--
-- TOC entry 12 (class 2615 OID 37942)
-- Name: finance; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA finance;


--
-- TOC entry 13 (class 2615 OID 37943)
-- Name: ops; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA ops;


--
-- TOC entry 14 (class 2615 OID 37944)
-- Name: people; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA people;


--
-- TOC entry 15 (class 2615 OID 37945)
-- Name: services; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA services;


--
-- TOC entry 2 (class 3079 OID 37946)
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- TOC entry 5580 (class 0 OID 0)
-- Dependencies: 2
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- TOC entry 3 (class 3079 OID 38027)
-- Name: unaccent; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;


--
-- TOC entry 5581 (class 0 OID 0)
-- Dependencies: 3
-- Name: EXTENSION unaccent; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION unaccent IS 'text search dictionary that removes accents';


--
-- TOC entry 4 (class 3079 OID 38034)
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- TOC entry 5582 (class 0 OID 0)
-- Dependencies: 4
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- TOC entry 1009 (class 1247 OID 38046)
-- Name: t_estado_matricula; Type: TYPE; Schema: academic; Owner: -
--

CREATE TYPE academic.t_estado_matricula AS ENUM (
    'activo',
    'completado',
    'retirado',
    'reprobado'
);


--
-- TOC entry 1012 (class 1247 OID 38056)
-- Name: t_estado_oferta; Type: TYPE; Schema: academic; Owner: -
--

CREATE TYPE academic.t_estado_oferta AS ENUM (
    'pendiente',
    'confirmado',
    'en_progreso',
    'completado',
    'cancelado'
);


--
-- TOC entry 1015 (class 1247 OID 38068)
-- Name: t_estado_pago; Type: TYPE; Schema: finance; Owner: -
--

CREATE TYPE finance.t_estado_pago AS ENUM (
    'pendiente',
    'abonado',
    'pagado',
    'anulado'
);


--
-- TOC entry 1018 (class 1247 OID 38078)
-- Name: t_estado_verificacion; Type: TYPE; Schema: finance; Owner: -
--

CREATE TYPE finance.t_estado_verificacion AS ENUM (
    'pendiente',
    'aprobado',
    'rechazado'
);


--
-- TOC entry 1021 (class 1247 OID 38086)
-- Name: t_metodo_pago; Type: TYPE; Schema: finance; Owner: -
--

CREATE TYPE finance.t_metodo_pago AS ENUM (
    'efectivo',
    'transferencia',
    'deposito',
    'tarjeta',
    'otro'
);


--
-- TOC entry 1024 (class 1247 OID 38098)
-- Name: t_estado_reserva; Type: TYPE; Schema: services; Owner: -
--

CREATE TYPE services.t_estado_reserva AS ENUM (
    'reservado',
    'confirmado',
    'en_progreso',
    'completado',
    'cancelado'
);


--
-- TOC entry 375 (class 1255 OID 38109)
-- Name: fn_actualizar_perfil_estudiante(); Type: FUNCTION; Schema: academic; Owner: -
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


--
-- TOC entry 376 (class 1255 OID 38110)
-- Name: fn_actualizar_resumen_curso(); Type: FUNCTION; Schema: academic; Owner: -
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


--
-- TOC entry 390 (class 1255 OID 38111)
-- Name: fn_validar_capacidad_curso(); Type: FUNCTION; Schema: academic; Owner: -
--

CREATE FUNCTION academic.fn_validar_capacidad_curso() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            DECLARE
                v_capacidad SMALLINT;
                v_inscritos INT;
            BEGIN
                -- Obtener capacidad del curso
                SELECT capacidad_maxima INTO v_capacidad
                FROM academic.cursos_abiertos
                WHERE id = NEW.curso_abierto_id;
                
                -- Contar matrículas activas (no retiradas/reprobadas)
                SELECT COUNT(*) INTO v_inscritos
                FROM academic.matriculas
                WHERE curso_abierto_id = NEW.curso_abierto_id 
                  AND estado IN ('activo', 'completado')
                  AND deleted_at IS NULL;
                
                -- Validar que no exceda capacidad
                IF v_inscritos >= v_capacidad THEN
                    RAISE EXCEPTION 'Capacidad máxima (%) del curso alcanzada. Inscritos actuales: %', 
                        v_capacidad, v_inscritos;
                END IF;
                
                RETURN NEW;
            END;
            $$;


--
-- TOC entry 392 (class 1255 OID 38112)
-- Name: fn_auditar_cambios_horario(); Type: FUNCTION; Schema: audit; Owner: -
--

CREATE FUNCTION audit.fn_auditar_cambios_horario() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            DECLARE
                v_datos_anteriores JSON;
                v_datos_nuevos JSON;
                v_accion VARCHAR;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    v_accion := 'INSERT';
                    v_datos_anteriores := NULL;
                    v_datos_nuevos := ROW_TO_JSON(NEW);
                ELSIF TG_OP = 'UPDATE' THEN
                    v_accion := 'UPDATE';
                    v_datos_anteriores := ROW_TO_JSON(OLD);
                    v_datos_nuevos := ROW_TO_JSON(NEW);
                ELSIF TG_OP = 'DELETE' THEN
                    v_accion := 'DELETE';
                    v_datos_anteriores := ROW_TO_JSON(OLD);
                    v_datos_nuevos := NULL;
                END IF;

                INSERT INTO audit.cambios_horario_auditoria 
                    (cambio_horario_id, matricula_origen_id, curso_abierto_antiguo_id, 
                     curso_abierto_nuevo_id, motivo, estado, accion, usuario_id, 
                     datos_anteriores, datos_nuevos)
                VALUES 
                    (COALESCE(NEW.id, OLD.id),
                     COALESCE(NEW.matricula_origen_id, OLD.matricula_origen_id),
                     COALESCE(NEW.curso_abierto_antiguo_id, OLD.curso_abierto_antiguo_id),
                     COALESCE(NEW.curso_abierto_nuevo_id, OLD.curso_abierto_nuevo_id),
                     COALESCE(NEW.motivo, OLD.motivo),
                     COALESCE(NEW.estado, OLD.estado),
                     v_accion,
                     CURRENT_USER,
                     v_datos_anteriores,
                     v_datos_nuevos);

                RETURN COALESCE(NEW, OLD);
            END;
            $$;


--
-- TOC entry 388 (class 1255 OID 38113)
-- Name: fn_set_updated_at(); Type: FUNCTION; Schema: core; Owner: -
--

CREATE FUNCTION core.fn_set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$;


--
-- TOC entry 389 (class 1255 OID 38114)
-- Name: fn_actualizar_cuenta_cobrar(); Type: FUNCTION; Schema: finance; Owner: -
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


--
-- TOC entry 391 (class 1255 OID 38115)
-- Name: fn_registrar_movimiento_caja(); Type: FUNCTION; Schema: finance; Owner: -
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


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 225 (class 1259 OID 38116)
-- Name: asesorias; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 226 (class 1259 OID 38127)
-- Name: asistencia_taller_estudiantes; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.asistencia_taller_estudiantes (
    id uuid NOT NULL,
    asistencia_taller_id uuid NOT NULL,
    inscripcion_taller_id uuid,
    participante_externo_id uuid,
    asistio boolean DEFAULT true NOT NULL,
    estado character varying(20),
    observaciones text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- TOC entry 227 (class 1259 OID 38133)
-- Name: asistencias; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.asistencias (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    matricula_id uuid NOT NULL,
    clase_id uuid NOT NULL,
    asistio boolean DEFAULT false,
    estado character varying(20),
    observaciones text
);


--
-- TOC entry 228 (class 1259 OID 38140)
-- Name: asistencias_talleres; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 229 (class 1259 OID 38147)
-- Name: cambios_horario; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.cambios_horario (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    matricula_origen_id uuid NOT NULL,
    curso_abierto_nuevo_id uuid NOT NULL,
    motivo text,
    autorizado_por uuid,
    fecha_cambio timestamp with time zone DEFAULT now()
);


--
-- TOC entry 230 (class 1259 OID 38154)
-- Name: catalogo_cursos; Type: TABLE; Schema: academic; Owner: -
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
    codigo character varying(50),
    requisitos_previos text,
    CONSTRAINT catalogo_cursos_categoria_check CHECK (((categoria)::text = ANY (ARRAY[('regular'::character varying)::text, ('personalizado'::character varying)::text, ('taller'::character varying)::text])))
);


--
-- TOC entry 231 (class 1259 OID 38165)
-- Name: certificados; Type: TABLE; Schema: academic; Owner: -
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
    deleted_at timestamp(0) without time zone,
    fecha_emitido timestamp without time zone,
    fecha_borrado timestamp without time zone,
    emitido_por uuid,
    borrado_por uuid,
    metodo_entrega character varying(50)
);


--
-- TOC entry 232 (class 1259 OID 38175)
-- Name: clases; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 233 (class 1259 OID 38181)
-- Name: clases_extras; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 234 (class 1259 OID 38189)
-- Name: comentarios_curso; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 235 (class 1259 OID 38198)
-- Name: cursos_abiertos; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 236 (class 1259 OID 38211)
-- Name: horarios; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.horarios (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nombre_referencial character varying(100) NOT NULL,
    dia_semana smallint[],
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    es_activo boolean DEFAULT true
);


--
-- TOC entry 237 (class 1259 OID 38218)
-- Name: horarios_dias; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.horarios_dias (
    id bigint NOT NULL,
    horario_id uuid NOT NULL,
    dia_semana smallint NOT NULL
);


--
-- TOC entry 5583 (class 0 OID 0)
-- Dependencies: 237
-- Name: COLUMN horarios_dias.dia_semana; Type: COMMENT; Schema: academic; Owner: -
--

COMMENT ON COLUMN academic.horarios_dias.dia_semana IS '1=Lunes, 2=Martes, ..., 7=Domingo';


--
-- TOC entry 238 (class 1259 OID 38221)
-- Name: horarios_dias_id_seq; Type: SEQUENCE; Schema: academic; Owner: -
--

CREATE SEQUENCE academic.horarios_dias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5584 (class 0 OID 0)
-- Dependencies: 238
-- Name: horarios_dias_id_seq; Type: SEQUENCE OWNED BY; Schema: academic; Owner: -
--

ALTER SEQUENCE academic.horarios_dias_id_seq OWNED BY academic.horarios_dias.id;


--
-- TOC entry 239 (class 1259 OID 38222)
-- Name: horarios_talleres; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.horarios_talleres (
    id uuid NOT NULL,
    taller_id uuid NOT NULL,
    dia_semana integer NOT NULL,
    hora_inicio time(0) without time zone NOT NULL,
    hora_fin time(0) without time zone NOT NULL,
    aula character varying(255),
    capacidad integer NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- TOC entry 240 (class 1259 OID 38225)
-- Name: inscripciones_externos_talleres; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.inscripciones_externos_talleres (
    id uuid NOT NULL,
    taller_id uuid NOT NULL,
    participante_externo_id uuid NOT NULL,
    fecha_inscripcion date NOT NULL,
    estado character varying(255) DEFAULT 'inscrito'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT inscripciones_externos_talleres_estado_check CHECK (((estado)::text = ANY (ARRAY[('inscrito'::character varying)::text, ('completado'::character varying)::text, ('retirado'::character varying)::text])))
);


--
-- TOC entry 241 (class 1259 OID 38230)
-- Name: inscripciones_taller; Type: TABLE; Schema: academic; Owner: -
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
    ciudad character varying(100),
    motivo_ajuste character varying(255)
);


--
-- TOC entry 242 (class 1259 OID 38239)
-- Name: inscripciones_talleres; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.inscripciones_talleres (
    id uuid NOT NULL,
    taller_id uuid NOT NULL,
    estudiante_id uuid NOT NULL,
    fecha_inscripcion date NOT NULL,
    estado character varying(255) DEFAULT 'inscrito'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT inscripciones_talleres_estado_check CHECK (((estado)::text = ANY (ARRAY[('inscrito'::character varying)::text, ('completado'::character varying)::text, ('retirado'::character varying)::text])))
);


--
-- TOC entry 243 (class 1259 OID 38244)
-- Name: matriculas; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 244 (class 1259 OID 38254)
-- Name: modulos; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 245 (class 1259 OID 38258)
-- Name: notas; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 246 (class 1259 OID 38265)
-- Name: participantes_cursos_personalizados; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.participantes_cursos_personalizados (
    id uuid NOT NULL,
    curso_personalizado_id uuid NOT NULL,
    participante_externo_id uuid NOT NULL,
    fecha_inscripcion date NOT NULL,
    estado character varying(255) DEFAULT 'inscrito'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT participantes_cursos_personalizados_estado_check CHECK (((estado)::text = ANY (ARRAY[('inscrito'::character varying)::text, ('completado'::character varying)::text, ('retirado'::character varying)::text])))
);


--
-- TOC entry 247 (class 1259 OID 38270)
-- Name: participantes_externos; Type: TABLE; Schema: academic; Owner: -
--

CREATE TABLE academic.participantes_externos (
    id uuid NOT NULL,
    nombre character varying(255) NOT NULL,
    email character varying(255),
    telefono character varying(255),
    institucion character varying(255),
    tipo character varying(255) DEFAULT 'persona_externa'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT participantes_externos_tipo_check CHECK (((tipo)::text = ANY (ARRAY[('persona_externa'::character varying)::text, ('profesional'::character varying)::text, ('estudiante_externo'::character varying)::text])))
);


--
-- TOC entry 248 (class 1259 OID 38277)
-- Name: solicitudes_inscripcion; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 249 (class 1259 OID 38289)
-- Name: talleres; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 250 (class 1259 OID 38300)
-- Name: traslados_modulo; Type: TABLE; Schema: academic; Owner: -
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


--
-- TOC entry 251 (class 1259 OID 38307)
-- Name: v_horarios_con_dias; Type: VIEW; Schema: academic; Owner: -
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


--
-- TOC entry 252 (class 1259 OID 38312)
-- Name: lineas_pago_modulo; Type: TABLE; Schema: finance; Owner: -
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


--
-- TOC entry 253 (class 1259 OID 38318)
-- Name: vista_cursos_finanzas; Type: VIEW; Schema: academic; Owner: -
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


--
-- TOC entry 254 (class 1259 OID 38323)
-- Name: cambios_horario_auditoria; Type: TABLE; Schema: audit; Owner: -
--

CREATE TABLE audit.cambios_horario_auditoria (
    id bigint NOT NULL,
    cambio_horario_id uuid,
    matricula_origen_id uuid,
    curso_abierto_antiguo_id uuid,
    curso_abierto_nuevo_id uuid,
    motivo character varying(255),
    estado character varying(255) DEFAULT 'pendiente'::character varying NOT NULL,
    accion character varying(50) NOT NULL,
    usuario_id character varying(255),
    datos_anteriores json,
    datos_nuevos json,
    fecha_cambio timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT cambios_horario_auditoria_estado_check CHECK (((estado)::text = ANY (ARRAY[('pendiente'::character varying)::text, ('aprobado'::character varying)::text, ('rechazado'::character varying)::text, ('completado'::character varying)::text])))
);


--
-- TOC entry 255 (class 1259 OID 38331)
-- Name: cambios_horario_auditoria_id_seq; Type: SEQUENCE; Schema: audit; Owner: -
--

CREATE SEQUENCE audit.cambios_horario_auditoria_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5585 (class 0 OID 0)
-- Dependencies: 255
-- Name: cambios_horario_auditoria_id_seq; Type: SEQUENCE OWNED BY; Schema: audit; Owner: -
--

ALTER SEQUENCE audit.cambios_horario_auditoria_id_seq OWNED BY audit.cambios_horario_auditoria.id;


--
-- TOC entry 256 (class 1259 OID 38332)
-- Name: eventos_financieros; Type: TABLE; Schema: audit; Owner: -
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


--
-- TOC entry 257 (class 1259 OID 38342)
-- Name: inicios_sesion; Type: TABLE; Schema: audit; Owner: -
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


--
-- TOC entry 258 (class 1259 OID 38350)
-- Name: archivos_eliminados; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.archivos_eliminados (
    id uuid NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id uuid NOT NULL,
    field_name character varying(100) NOT NULL,
    file_path character varying(500) NOT NULL,
    accion character varying(20) NOT NULL,
    eliminado_por uuid,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- TOC entry 259 (class 1259 OID 38356)
-- Name: cache; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- TOC entry 260 (class 1259 OID 38361)
-- Name: cache_locks; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- TOC entry 261 (class 1259 OID 38366)
-- Name: ciudades; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.ciudades (
    id bigint NOT NULL,
    nombre character varying(100) NOT NULL,
    deleted_at timestamp with time zone
);


--
-- TOC entry 262 (class 1259 OID 38369)
-- Name: ciudades_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.ciudades_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5586 (class 0 OID 0)
-- Dependencies: 262
-- Name: ciudades_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.ciudades_id_seq OWNED BY core.ciudades.id;


--
-- TOC entry 263 (class 1259 OID 38370)
-- Name: estudiante_segmentos; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.estudiante_segmentos (
    id uuid NOT NULL,
    nombre character varying(255) NOT NULL,
    descripcion text,
    criterios json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- TOC entry 264 (class 1259 OID 38375)
-- Name: failed_jobs; Type: TABLE; Schema: core; Owner: -
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


--
-- TOC entry 265 (class 1259 OID 38381)
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5587 (class 0 OID 0)
-- Dependencies: 265
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.failed_jobs_id_seq OWNED BY core.failed_jobs.id;


--
-- TOC entry 266 (class 1259 OID 38382)
-- Name: job_batches; Type: TABLE; Schema: core; Owner: -
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


--
-- TOC entry 267 (class 1259 OID 38387)
-- Name: jobs; Type: TABLE; Schema: core; Owner: -
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


--
-- TOC entry 268 (class 1259 OID 38392)
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5588 (class 0 OID 0)
-- Dependencies: 268
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.jobs_id_seq OWNED BY core.jobs.id;


--
-- TOC entry 269 (class 1259 OID 38393)
-- Name: migrations; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- TOC entry 270 (class 1259 OID 38396)
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5589 (class 0 OID 0)
-- Dependencies: 270
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.migrations_id_seq OWNED BY core.migrations.id;


--
-- TOC entry 271 (class 1259 OID 38397)
-- Name: model_has_permissions; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


--
-- TOC entry 272 (class 1259 OID 38400)
-- Name: model_has_roles; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id uuid NOT NULL
);


--
-- TOC entry 273 (class 1259 OID 38403)
-- Name: password_reset_tokens; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- TOC entry 274 (class 1259 OID 38408)
-- Name: permissions; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- TOC entry 275 (class 1259 OID 38413)
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5590 (class 0 OID 0)
-- Dependencies: 275
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.permissions_id_seq OWNED BY core.permissions.id;


--
-- TOC entry 276 (class 1259 OID 38414)
-- Name: role_has_permissions; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- TOC entry 277 (class 1259 OID 38417)
-- Name: roles; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- TOC entry 278 (class 1259 OID 38422)
-- Name: roles_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5591 (class 0 OID 0)
-- Dependencies: 278
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.roles_id_seq OWNED BY core.roles.id;


--
-- TOC entry 279 (class 1259 OID 38423)
-- Name: sessions; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- TOC entry 280 (class 1259 OID 38428)
-- Name: users; Type: TABLE; Schema: core; Owner: -
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


--
-- TOC entry 281 (class 1259 OID 38433)
-- Name: users_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5592 (class 0 OID 0)
-- Dependencies: 281
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.users_id_seq OWNED BY core.users.id;


--
-- TOC entry 282 (class 1259 OID 38434)
-- Name: categorias_egreso; Type: TABLE; Schema: finance; Owner: -
--

CREATE TABLE finance.categorias_egreso (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    tipo_general character varying(50)
);


--
-- TOC entry 283 (class 1259 OID 38437)
-- Name: categorias_egreso_id_seq; Type: SEQUENCE; Schema: finance; Owner: -
--

CREATE SEQUENCE finance.categorias_egreso_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5593 (class 0 OID 0)
-- Dependencies: 283
-- Name: categorias_egreso_id_seq; Type: SEQUENCE OWNED BY; Schema: finance; Owner: -
--

ALTER SEQUENCE finance.categorias_egreso_id_seq OWNED BY finance.categorias_egreso.id;


--
-- TOC entry 284 (class 1259 OID 38438)
-- Name: cuentas_por_cobrar; Type: TABLE; Schema: finance; Owner: -
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
    CONSTRAINT chk_un_origen CHECK ((num_nonnulls(matricula_id, inscripcion_taller_id, reserva_aula_id, reserva_podcast_id, reserva_radio_id, servicio_streaming_id, servicio_produccion_id, edicion_video_id, alquiler_equipo_id, clase_extra_id, asesoria_id, solicitud_inscripcion_id) = 1))
);


--
-- TOC entry 285 (class 1259 OID 38449)
-- Name: horas_instructor; Type: TABLE; Schema: finance; Owner: -
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


--
-- TOC entry 286 (class 1259 OID 38456)
-- Name: resumen_caja; Type: TABLE; Schema: finance; Owner: -
--

CREATE TABLE finance.resumen_caja (
    id smallint DEFAULT 1 NOT NULL,
    total_ingresos numeric(14,2) DEFAULT 0 NOT NULL,
    total_egresos numeric(14,2) DEFAULT 0 NOT NULL,
    saldo_actual numeric(14,2) DEFAULT 0 NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_resumen_caja_singleton CHECK ((id = 1))
);


--
-- TOC entry 287 (class 1259 OID 38465)
-- Name: transacciones_egreso; Type: TABLE; Schema: finance; Owner: -
--

CREATE TABLE finance.transacciones_egreso (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    categoria_id integer NOT NULL,
    descripcion text NOT NULL,
    monto numeric(10,2) NOT NULL,
    comprobante_url text,
    fecha_pago timestamp with time zone DEFAULT now(),
    registrado_por uuid,
    subcategoria character varying(100),
    proveedor_beneficiario character varying(200),
    metodo_pago character varying(50) DEFAULT 'transferencia'::character varying,
    notas text,
    CONSTRAINT transacciones_egreso_monto_check CHECK ((monto > (0)::numeric))
);


--
-- TOC entry 288 (class 1259 OID 38474)
-- Name: transacciones_ingreso; Type: TABLE; Schema: finance; Owner: -
--

CREATE TABLE finance.transacciones_ingreso (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    cuenta_cobrar_id uuid,
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
    referencia_pago character varying(100),
    CONSTRAINT transacciones_ingreso_monto_check CHECK ((monto > (0)::numeric))
);


--
-- TOC entry 289 (class 1259 OID 38483)
-- Name: vista_balance_mensual; Type: VIEW; Schema: finance; Owner: -
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


--
-- TOC entry 290 (class 1259 OID 38488)
-- Name: personas; Type: TABLE; Schema: people; Owner: -
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
    deleted_at timestamp with time zone,
    ciudad character varying(100)
);


--
-- TOC entry 291 (class 1259 OID 38497)
-- Name: vista_horas_instructor; Type: VIEW; Schema: finance; Owner: -
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


--
-- TOC entry 292 (class 1259 OID 38502)
-- Name: vista_movimientos_caja; Type: VIEW; Schema: finance; Owner: -
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


--
-- TOC entry 293 (class 1259 OID 38507)
-- Name: registro_asistencia_staff; Type: TABLE; Schema: ops; Owner: -
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


--
-- TOC entry 294 (class 1259 OID 38514)
-- Name: tareas_staff; Type: TABLE; Schema: ops; Owner: -
--

CREATE TABLE ops.tareas_staff (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    titulo character varying(200) NOT NULL,
    descripcion text,
    persona_id uuid NOT NULL,
    fecha_inicio date NOT NULL,
    fecha_fin date,
    estado character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    deleted_at timestamp with time zone,
    created_by uuid,
    CONSTRAINT tareas_staff_estado_check CHECK (((estado)::text = ANY (ARRAY[('pendiente'::character varying)::text, ('en_progreso'::character varying)::text, ('completada'::character varying)::text, ('cancelada'::character varying)::text])))
);


--
-- TOC entry 295 (class 1259 OID 38524)
-- Name: clientes_externos; Type: TABLE; Schema: people; Owner: -
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
    ciudad character varying(100),
    es_cliente boolean DEFAULT false NOT NULL
);


--
-- TOC entry 296 (class 1259 OID 38531)
-- Name: aulas; Type: TABLE; Schema: services; Owner: -
--

CREATE TABLE services.aulas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nombre character varying(100) NOT NULL,
    capacidad smallint NOT NULL,
    precio_hora numeric(10,2) NOT NULL,
    caracteristicas text
);


--
-- TOC entry 297 (class 1259 OID 38537)
-- Name: paquetes_podcast; Type: TABLE; Schema: services; Owner: -
--

CREATE TABLE services.paquetes_podcast (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    precio_base numeric(10,2) NOT NULL,
    es_activo boolean DEFAULT true
);


--
-- TOC entry 298 (class 1259 OID 38543)
-- Name: reservas_aulas; Type: TABLE; Schema: services; Owner: -
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


--
-- TOC entry 299 (class 1259 OID 38549)
-- Name: reservas_podcast; Type: TABLE; Schema: services; Owner: -
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
    titulo character varying(255),
    CONSTRAINT chk_cliente_podcast CHECK ((num_nonnulls(persona_id, cliente_externo_id) = 1))
);


--
-- TOC entry 300 (class 1259 OID 38557)
-- Name: servicios_streaming; Type: TABLE; Schema: services; Owner: -
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


--
-- TOC entry 301 (class 1259 OID 38566)
-- Name: vista_agenda_unificada; Type: VIEW; Schema: ops; Owner: -
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


--
-- TOC entry 302 (class 1259 OID 38571)
-- Name: cuentas_sistema; Type: TABLE; Schema: people; Owner: -
--

CREATE TABLE people.cuentas_sistema (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    username character varying(100) NOT NULL,
    password_hash character varying(500) NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    last_login timestamp with time zone
);


--
-- TOC entry 303 (class 1259 OID 38578)
-- Name: perfil_estudiante; Type: TABLE; Schema: people; Owner: -
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
    ciudad character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- TOC entry 304 (class 1259 OID 38585)
-- Name: perfil_instructor; Type: TABLE; Schema: people; Owner: -
--

CREATE TABLE people.perfil_instructor (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    especialidad character varying(200),
    bio text
);


--
-- TOC entry 305 (class 1259 OID 38591)
-- Name: perfil_staff; Type: TABLE; Schema: people; Owner: -
--

CREATE TABLE people.perfil_staff (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    cargo character varying(100) NOT NULL,
    salario_base numeric(10,2),
    fecha_ingreso date,
    es_pasante boolean DEFAULT false
);


--
-- TOC entry 306 (class 1259 OID 38596)
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer
);


--
-- TOC entry 307 (class 1259 OID 38601)
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer
);


--
-- TOC entry 308 (class 1259 OID 38606)
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
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


--
-- TOC entry 309 (class 1259 OID 38612)
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5594 (class 0 OID 0)
-- Dependencies: 309
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- TOC entry 310 (class 1259 OID 38613)
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
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


--
-- TOC entry 311 (class 1259 OID 38618)
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
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


--
-- TOC entry 312 (class 1259 OID 38623)
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5595 (class 0 OID 0)
-- Dependencies: 312
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- TOC entry 313 (class 1259 OID 38624)
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- TOC entry 314 (class 1259 OID 38627)
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5596 (class 0 OID 0)
-- Dependencies: 314
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- TOC entry 315 (class 1259 OID 38628)
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
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


--
-- TOC entry 316 (class 1259 OID 38633)
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5597 (class 0 OID 0)
-- Dependencies: 316
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- TOC entry 317 (class 1259 OID 38634)
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- TOC entry 318 (class 1259 OID 38639)
-- Name: alquiler_equipos; Type: TABLE; Schema: services; Owner: -
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


--
-- TOC entry 319 (class 1259 OID 38648)
-- Name: asignaciones_personal; Type: TABLE; Schema: services; Owner: -
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


--
-- TOC entry 320 (class 1259 OID 38653)
-- Name: edicion_videos; Type: TABLE; Schema: services; Owner: -
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


--
-- TOC entry 321 (class 1259 OID 38662)
-- Name: equipos; Type: TABLE; Schema: services; Owner: -
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


--
-- TOC entry 322 (class 1259 OID 38671)
-- Name: items_paquete_podcast; Type: TABLE; Schema: services; Owner: -
--

CREATE TABLE services.items_paquete_podcast (
    id integer NOT NULL,
    paquete_id integer NOT NULL,
    descripcion character varying(200) NOT NULL
);


--
-- TOC entry 323 (class 1259 OID 38674)
-- Name: items_paquete_podcast_id_seq; Type: SEQUENCE; Schema: services; Owner: -
--

CREATE SEQUENCE services.items_paquete_podcast_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5598 (class 0 OID 0)
-- Dependencies: 323
-- Name: items_paquete_podcast_id_seq; Type: SEQUENCE OWNED BY; Schema: services; Owner: -
--

ALTER SEQUENCE services.items_paquete_podcast_id_seq OWNED BY services.items_paquete_podcast.id;


--
-- TOC entry 324 (class 1259 OID 38675)
-- Name: paquetes_podcast_id_seq; Type: SEQUENCE; Schema: services; Owner: -
--

CREATE SEQUENCE services.paquetes_podcast_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5599 (class 0 OID 0)
-- Dependencies: 324
-- Name: paquetes_podcast_id_seq; Type: SEQUENCE OWNED BY; Schema: services; Owner: -
--

ALTER SEQUENCE services.paquetes_podcast_id_seq OWNED BY services.paquetes_podcast.id;


--
-- TOC entry 325 (class 1259 OID 38676)
-- Name: reservas_radio; Type: TABLE; Schema: services; Owner: -
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


--
-- TOC entry 326 (class 1259 OID 38687)
-- Name: servicios_produccion; Type: TABLE; Schema: services; Owner: -
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


--
-- TOC entry 327 (class 1259 OID 38696)
-- Name: tarifas_radio; Type: TABLE; Schema: services; Owner: -
--

CREATE TABLE services.tarifas_radio (
    id bigint NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    precio_por_hora numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    incluye_operador boolean DEFAULT true NOT NULL,
    es_activo boolean DEFAULT true NOT NULL
);


--
-- TOC entry 328 (class 1259 OID 38704)
-- Name: tarifas_radio_id_seq; Type: SEQUENCE; Schema: services; Owner: -
--

CREATE SEQUENCE services.tarifas_radio_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5600 (class 0 OID 0)
-- Dependencies: 328
-- Name: tarifas_radio_id_seq; Type: SEQUENCE OWNED BY; Schema: services; Owner: -
--

ALTER SEQUENCE services.tarifas_radio_id_seq OWNED BY services.tarifas_radio.id;


--
-- TOC entry 329 (class 1259 OID 38705)
-- Name: trabajos_edicion; Type: TABLE; Schema: services; Owner: -
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
    cliente_externo_id uuid,
    persona_id uuid,
    CONSTRAINT trabajos_edicion_estado_check CHECK (((estado)::text = ANY (ARRAY[('recibido'::character varying)::text, ('en_proceso'::character varying)::text, ('revision'::character varying)::text, ('entregado'::character varying)::text]))),
    CONSTRAINT trabajos_edicion_nivel_check CHECK (((nivel)::text = ANY (ARRAY[('basica'::character varying)::text, ('estandar'::character varying)::text, ('premium'::character varying)::text])))
);


--
-- TOC entry 4792 (class 2604 OID 38717)
-- Name: horarios_dias id; Type: DEFAULT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.horarios_dias ALTER COLUMN id SET DEFAULT nextval('academic.horarios_dias_id_seq'::regclass);


--
-- TOC entry 4821 (class 2604 OID 38718)
-- Name: cambios_horario_auditoria id; Type: DEFAULT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.cambios_horario_auditoria ALTER COLUMN id SET DEFAULT nextval('audit.cambios_horario_auditoria_id_seq'::regclass);


--
-- TOC entry 4831 (class 2604 OID 38719)
-- Name: ciudades id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.ciudades ALTER COLUMN id SET DEFAULT nextval('core.ciudades_id_seq'::regclass);


--
-- TOC entry 4832 (class 2604 OID 38720)
-- Name: failed_jobs id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.failed_jobs ALTER COLUMN id SET DEFAULT nextval('core.failed_jobs_id_seq'::regclass);


--
-- TOC entry 4834 (class 2604 OID 38721)
-- Name: jobs id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.jobs ALTER COLUMN id SET DEFAULT nextval('core.jobs_id_seq'::regclass);


--
-- TOC entry 4835 (class 2604 OID 38722)
-- Name: migrations id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.migrations ALTER COLUMN id SET DEFAULT nextval('core.migrations_id_seq'::regclass);


--
-- TOC entry 4836 (class 2604 OID 38723)
-- Name: permissions id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.permissions ALTER COLUMN id SET DEFAULT nextval('core.permissions_id_seq'::regclass);


--
-- TOC entry 4837 (class 2604 OID 38724)
-- Name: roles id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.roles ALTER COLUMN id SET DEFAULT nextval('core.roles_id_seq'::regclass);


--
-- TOC entry 4838 (class 2604 OID 38725)
-- Name: users id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.users ALTER COLUMN id SET DEFAULT nextval('core.users_id_seq'::regclass);


--
-- TOC entry 4839 (class 2604 OID 38726)
-- Name: categorias_egreso id; Type: DEFAULT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.categorias_egreso ALTER COLUMN id SET DEFAULT nextval('finance.categorias_egreso_id_seq'::regclass);


--
-- TOC entry 4891 (class 2604 OID 38727)
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- TOC entry 4893 (class 2604 OID 38728)
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- TOC entry 4894 (class 2604 OID 38729)
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- TOC entry 4895 (class 2604 OID 38730)
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- TOC entry 4905 (class 2604 OID 38731)
-- Name: items_paquete_podcast id; Type: DEFAULT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.items_paquete_podcast ALTER COLUMN id SET DEFAULT nextval('services.items_paquete_podcast_id_seq'::regclass);


--
-- TOC entry 4875 (class 2604 OID 38732)
-- Name: paquetes_podcast id; Type: DEFAULT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.paquetes_podcast ALTER COLUMN id SET DEFAULT nextval('services.paquetes_podcast_id_seq'::regclass);


--
-- TOC entry 4913 (class 2604 OID 38733)
-- Name: tarifas_radio id; Type: DEFAULT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.tarifas_radio ALTER COLUMN id SET DEFAULT nextval('services.tarifas_radio_id_seq'::regclass);


--
-- TOC entry 4977 (class 2606 OID 38735)
-- Name: asistencias_talleres academic_asistencias_talleres_taller_id_fecha_sesion_unique; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencias_talleres
    ADD CONSTRAINT academic_asistencias_talleres_taller_id_fecha_sesion_unique UNIQUE (taller_id, fecha_sesion);


--
-- TOC entry 4986 (class 2606 OID 38737)
-- Name: catalogo_cursos academic_catalogo_cursos_codigo_unique; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.catalogo_cursos
    ADD CONSTRAINT academic_catalogo_cursos_codigo_unique UNIQUE (codigo);


--
-- TOC entry 5020 (class 2606 OID 38739)
-- Name: horarios_dias academic_horarios_dias_horario_id_dia_semana_unique; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.horarios_dias
    ADD CONSTRAINT academic_horarios_dias_horario_id_dia_semana_unique UNIQUE (horario_id, dia_semana);


--
-- TOC entry 5033 (class 2606 OID 38741)
-- Name: inscripciones_externos_talleres academic_inscripciones_externos_talleres_taller_id_participante; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_externos_talleres
    ADD CONSTRAINT academic_inscripciones_externos_talleres_taller_id_participante UNIQUE (taller_id, participante_externo_id);


--
-- TOC entry 5040 (class 2606 OID 38743)
-- Name: inscripciones_talleres academic_inscripciones_talleres_taller_id_estudiante_id_unique; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_talleres
    ADD CONSTRAINT academic_inscripciones_talleres_taller_id_estudiante_id_unique UNIQUE (taller_id, estudiante_id);


--
-- TOC entry 4960 (class 2606 OID 38745)
-- Name: asesorias asesorias_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asesorias
    ADD CONSTRAINT asesorias_pkey PRIMARY KEY (id);


--
-- TOC entry 4963 (class 2606 OID 38747)
-- Name: asistencia_taller_estudiantes asistencia_taller_estudiantes_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencia_taller_estudiantes
    ADD CONSTRAINT asistencia_taller_estudiantes_pkey PRIMARY KEY (id);


--
-- TOC entry 4969 (class 2606 OID 38749)
-- Name: asistencias asistencias_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencias
    ADD CONSTRAINT asistencias_pkey PRIMARY KEY (id);


--
-- TOC entry 4980 (class 2606 OID 38751)
-- Name: asistencias_talleres asistencias_talleres_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencias_talleres
    ADD CONSTRAINT asistencias_talleres_pkey PRIMARY KEY (id);


--
-- TOC entry 4965 (class 2606 OID 38753)
-- Name: asistencia_taller_estudiantes at_est_externo_unique; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencia_taller_estudiantes
    ADD CONSTRAINT at_est_externo_unique UNIQUE (asistencia_taller_id, participante_externo_id);


--
-- TOC entry 4967 (class 2606 OID 38755)
-- Name: asistencia_taller_estudiantes at_est_inscripcion_unique; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencia_taller_estudiantes
    ADD CONSTRAINT at_est_inscripcion_unique UNIQUE (asistencia_taller_id, inscripcion_taller_id);


--
-- TOC entry 4982 (class 2606 OID 38757)
-- Name: cambios_horario cambios_horario_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cambios_horario
    ADD CONSTRAINT cambios_horario_pkey PRIMARY KEY (id);


--
-- TOC entry 4988 (class 2606 OID 38759)
-- Name: catalogo_cursos catalogo_cursos_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.catalogo_cursos
    ADD CONSTRAINT catalogo_cursos_pkey PRIMARY KEY (id);


--
-- TOC entry 4994 (class 2606 OID 38761)
-- Name: certificados certificados_codigo_certificado_key; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_codigo_certificado_key UNIQUE (codigo_certificado);


--
-- TOC entry 4996 (class 2606 OID 38763)
-- Name: certificados certificados_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_pkey PRIMARY KEY (id);


--
-- TOC entry 5007 (class 2606 OID 38765)
-- Name: clases_extras clases_extras_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.clases_extras
    ADD CONSTRAINT clases_extras_pkey PRIMARY KEY (id);


--
-- TOC entry 5003 (class 2606 OID 38767)
-- Name: clases clases_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.clases
    ADD CONSTRAINT clases_pkey PRIMARY KEY (id);


--
-- TOC entry 5009 (class 2606 OID 38769)
-- Name: comentarios_curso comentarios_curso_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.comentarios_curso
    ADD CONSTRAINT comentarios_curso_pkey PRIMARY KEY (id);


--
-- TOC entry 5011 (class 2606 OID 38771)
-- Name: cursos_abiertos cursos_abiertos_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_pkey PRIMARY KEY (id);


--
-- TOC entry 5023 (class 2606 OID 38773)
-- Name: horarios_dias horarios_dias_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.horarios_dias
    ADD CONSTRAINT horarios_dias_pkey PRIMARY KEY (id);


--
-- TOC entry 5017 (class 2606 OID 38775)
-- Name: horarios horarios_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.horarios
    ADD CONSTRAINT horarios_pkey PRIMARY KEY (id);


--
-- TOC entry 5029 (class 2606 OID 38777)
-- Name: horarios_talleres horarios_talleres_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.horarios_talleres
    ADD CONSTRAINT horarios_talleres_pkey PRIMARY KEY (id);


--
-- TOC entry 5035 (class 2606 OID 38779)
-- Name: inscripciones_externos_talleres inscripciones_externos_talleres_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_externos_talleres
    ADD CONSTRAINT inscripciones_externos_talleres_pkey PRIMARY KEY (id);


--
-- TOC entry 5037 (class 2606 OID 38781)
-- Name: inscripciones_taller inscripciones_taller_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_taller
    ADD CONSTRAINT inscripciones_taller_pkey PRIMARY KEY (id);


--
-- TOC entry 5043 (class 2606 OID 38783)
-- Name: inscripciones_talleres inscripciones_talleres_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_talleres
    ADD CONSTRAINT inscripciones_talleres_pkey PRIMARY KEY (id);


--
-- TOC entry 5054 (class 2606 OID 38785)
-- Name: matriculas matriculas_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT matriculas_pkey PRIMARY KEY (id);


--
-- TOC entry 5058 (class 2606 OID 38787)
-- Name: modulos modulos_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.modulos
    ADD CONSTRAINT modulos_pkey PRIMARY KEY (id);


--
-- TOC entry 5063 (class 2606 OID 38789)
-- Name: notas notas_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.notas
    ADD CONSTRAINT notas_pkey PRIMARY KEY (id);


--
-- TOC entry 5069 (class 2606 OID 38791)
-- Name: participantes_cursos_personalizados participantes_cursos_personalizados_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.participantes_cursos_personalizados
    ADD CONSTRAINT participantes_cursos_personalizados_pkey PRIMARY KEY (id);


--
-- TOC entry 5075 (class 2606 OID 38793)
-- Name: participantes_externos participantes_externos_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.participantes_externos
    ADD CONSTRAINT participantes_externos_pkey PRIMARY KEY (id);


--
-- TOC entry 5071 (class 2606 OID 38795)
-- Name: participantes_cursos_personalizados pcp_curso_part_unique; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.participantes_cursos_personalizados
    ADD CONSTRAINT pcp_curso_part_unique UNIQUE (curso_personalizado_id, participante_externo_id);


--
-- TOC entry 5083 (class 2606 OID 38797)
-- Name: solicitudes_inscripcion solicitudes_inscripcion_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT solicitudes_inscripcion_pkey PRIMARY KEY (id);


--
-- TOC entry 5085 (class 2606 OID 38799)
-- Name: talleres talleres_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.talleres
    ADD CONSTRAINT talleres_pkey PRIMARY KEY (id);


--
-- TOC entry 5087 (class 2606 OID 38801)
-- Name: traslados_modulo traslados_modulo_pkey; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_pkey PRIMARY KEY (id);


--
-- TOC entry 4974 (class 2606 OID 38803)
-- Name: asistencias uq_asistencia; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencias
    ADD CONSTRAINT uq_asistencia UNIQUE (matricula_id, clase_id);


--
-- TOC entry 5056 (class 2606 OID 38805)
-- Name: matriculas uq_estudiante_curso; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT uq_estudiante_curso UNIQUE (estudiante_id, curso_abierto_id);


--
-- TOC entry 5065 (class 2606 OID 38807)
-- Name: notas uq_nota_modulo; Type: CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.notas
    ADD CONSTRAINT uq_nota_modulo UNIQUE (matricula_id, modulo_id);


--
-- TOC entry 5096 (class 2606 OID 38809)
-- Name: cambios_horario_auditoria cambios_horario_auditoria_pkey; Type: CONSTRAINT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.cambios_horario_auditoria
    ADD CONSTRAINT cambios_horario_auditoria_pkey PRIMARY KEY (id);


--
-- TOC entry 5098 (class 2606 OID 38811)
-- Name: eventos_financieros eventos_financieros_pkey; Type: CONSTRAINT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.eventos_financieros
    ADD CONSTRAINT eventos_financieros_pkey PRIMARY KEY (id);


--
-- TOC entry 5102 (class 2606 OID 38813)
-- Name: inicios_sesion inicios_sesion_pkey; Type: CONSTRAINT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.inicios_sesion
    ADD CONSTRAINT inicios_sesion_pkey PRIMARY KEY (id);


--
-- TOC entry 5107 (class 2606 OID 38815)
-- Name: archivos_eliminados archivos_eliminados_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.archivos_eliminados
    ADD CONSTRAINT archivos_eliminados_pkey PRIMARY KEY (id);


--
-- TOC entry 5114 (class 2606 OID 38817)
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- TOC entry 5111 (class 2606 OID 38819)
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- TOC entry 5116 (class 2606 OID 38821)
-- Name: ciudades ciudades_nombre_key; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.ciudades
    ADD CONSTRAINT ciudades_nombre_key UNIQUE (nombre);


--
-- TOC entry 5118 (class 2606 OID 38823)
-- Name: ciudades ciudades_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.ciudades
    ADD CONSTRAINT ciudades_pkey PRIMARY KEY (id);


--
-- TOC entry 5142 (class 2606 OID 38825)
-- Name: permissions core_permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.permissions
    ADD CONSTRAINT core_permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- TOC entry 5148 (class 2606 OID 38827)
-- Name: roles core_roles_name_guard_name_unique; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.roles
    ADD CONSTRAINT core_roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- TOC entry 5120 (class 2606 OID 38829)
-- Name: estudiante_segmentos estudiante_segmentos_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.estudiante_segmentos
    ADD CONSTRAINT estudiante_segmentos_pkey PRIMARY KEY (id);


--
-- TOC entry 5123 (class 2606 OID 38831)
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- TOC entry 5125 (class 2606 OID 38833)
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- TOC entry 5127 (class 2606 OID 38835)
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- TOC entry 5129 (class 2606 OID 38837)
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- TOC entry 5132 (class 2606 OID 38839)
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- TOC entry 5135 (class 2606 OID 38841)
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- TOC entry 5138 (class 2606 OID 38843)
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- TOC entry 5140 (class 2606 OID 38845)
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- TOC entry 5144 (class 2606 OID 38847)
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- TOC entry 5146 (class 2606 OID 38849)
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- TOC entry 5150 (class 2606 OID 38851)
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- TOC entry 5153 (class 2606 OID 38853)
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- TOC entry 5156 (class 2606 OID 38855)
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- TOC entry 5158 (class 2606 OID 38857)
-- Name: users users_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- TOC entry 5160 (class 2606 OID 38859)
-- Name: categorias_egreso categorias_egreso_nombre_key; Type: CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.categorias_egreso
    ADD CONSTRAINT categorias_egreso_nombre_key UNIQUE (nombre);


--
-- TOC entry 5162 (class 2606 OID 38861)
-- Name: categorias_egreso categorias_egreso_pkey; Type: CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.categorias_egreso
    ADD CONSTRAINT categorias_egreso_pkey PRIMARY KEY (id);


--
-- TOC entry 5164 (class 2606 OID 38863)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_pkey; Type: CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_pkey PRIMARY KEY (id);


--
-- TOC entry 5180 (class 2606 OID 38865)
-- Name: horas_instructor horas_instructor_pkey; Type: CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_pkey PRIMARY KEY (id);


--
-- TOC entry 5091 (class 2606 OID 38867)
-- Name: lineas_pago_modulo lineas_pago_modulo_pkey; Type: CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.lineas_pago_modulo
    ADD CONSTRAINT lineas_pago_modulo_pkey PRIMARY KEY (id);


--
-- TOC entry 5183 (class 2606 OID 38869)
-- Name: resumen_caja resumen_caja_pkey; Type: CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.resumen_caja
    ADD CONSTRAINT resumen_caja_pkey PRIMARY KEY (id);


--
-- TOC entry 5186 (class 2606 OID 38871)
-- Name: transacciones_egreso transacciones_egreso_pkey; Type: CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.transacciones_egreso
    ADD CONSTRAINT transacciones_egreso_pkey PRIMARY KEY (id);


--
-- TOC entry 5189 (class 2606 OID 38873)
-- Name: transacciones_ingreso transacciones_ingreso_pkey; Type: CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.transacciones_ingreso
    ADD CONSTRAINT transacciones_ingreso_pkey PRIMARY KEY (id);


--
-- TOC entry 5200 (class 2606 OID 38875)
-- Name: registro_asistencia_staff registro_asistencia_staff_pkey; Type: CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.registro_asistencia_staff
    ADD CONSTRAINT registro_asistencia_staff_pkey PRIMARY KEY (id);


--
-- TOC entry 5202 (class 2606 OID 38877)
-- Name: registro_asistencia_staff uq_staff_dia; Type: CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.registro_asistencia_staff
    ADD CONSTRAINT uq_staff_dia UNIQUE (persona_id, fecha);


--
-- TOC entry 5206 (class 2606 OID 38879)
-- Name: clientes_externos clientes_externos_pkey; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.clientes_externos
    ADD CONSTRAINT clientes_externos_pkey PRIMARY KEY (id);


--
-- TOC entry 5227 (class 2606 OID 38881)
-- Name: cuentas_sistema cuentas_sistema_persona_id_key; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.cuentas_sistema
    ADD CONSTRAINT cuentas_sistema_persona_id_key UNIQUE (persona_id);


--
-- TOC entry 5229 (class 2606 OID 38883)
-- Name: cuentas_sistema cuentas_sistema_pkey; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.cuentas_sistema
    ADD CONSTRAINT cuentas_sistema_pkey PRIMARY KEY (id);


--
-- TOC entry 5231 (class 2606 OID 38885)
-- Name: cuentas_sistema cuentas_sistema_username_key; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.cuentas_sistema
    ADD CONSTRAINT cuentas_sistema_username_key UNIQUE (username);


--
-- TOC entry 5233 (class 2606 OID 38887)
-- Name: perfil_estudiante perfil_estudiante_persona_id_key; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_estudiante
    ADD CONSTRAINT perfil_estudiante_persona_id_key UNIQUE (persona_id);


--
-- TOC entry 5235 (class 2606 OID 38889)
-- Name: perfil_estudiante perfil_estudiante_pkey; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_estudiante
    ADD CONSTRAINT perfil_estudiante_pkey PRIMARY KEY (id);


--
-- TOC entry 5237 (class 2606 OID 38891)
-- Name: perfil_instructor perfil_instructor_persona_id_key; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_instructor
    ADD CONSTRAINT perfil_instructor_persona_id_key UNIQUE (persona_id);


--
-- TOC entry 5239 (class 2606 OID 38893)
-- Name: perfil_instructor perfil_instructor_pkey; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_instructor
    ADD CONSTRAINT perfil_instructor_pkey PRIMARY KEY (id);


--
-- TOC entry 5241 (class 2606 OID 38895)
-- Name: perfil_staff perfil_staff_persona_id_key; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_staff
    ADD CONSTRAINT perfil_staff_persona_id_key UNIQUE (persona_id);


--
-- TOC entry 5243 (class 2606 OID 38897)
-- Name: perfil_staff perfil_staff_pkey; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_staff
    ADD CONSTRAINT perfil_staff_pkey PRIMARY KEY (id);


--
-- TOC entry 5195 (class 2606 OID 38899)
-- Name: personas personas_cedula_key; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.personas
    ADD CONSTRAINT personas_cedula_key UNIQUE (cedula);


--
-- TOC entry 5197 (class 2606 OID 38901)
-- Name: personas personas_pkey; Type: CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.personas
    ADD CONSTRAINT personas_pkey PRIMARY KEY (id);


--
-- TOC entry 5247 (class 2606 OID 38903)
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- TOC entry 5245 (class 2606 OID 38905)
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- TOC entry 5249 (class 2606 OID 38907)
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- TOC entry 5251 (class 2606 OID 38909)
-- Name: failed_jobs failed_jobs_uuid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_key UNIQUE (uuid);


--
-- TOC entry 5253 (class 2606 OID 38911)
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- TOC entry 5255 (class 2606 OID 38913)
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- TOC entry 5257 (class 2606 OID 38915)
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- TOC entry 5259 (class 2606 OID 38917)
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- TOC entry 5261 (class 2606 OID 38919)
-- Name: personal_access_tokens personal_access_tokens_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_key UNIQUE (token);


--
-- TOC entry 5263 (class 2606 OID 38921)
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- TOC entry 5265 (class 2606 OID 38923)
-- Name: alquiler_equipos alquiler_equipos_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.alquiler_equipos
    ADD CONSTRAINT alquiler_equipos_pkey PRIMARY KEY (id);


--
-- TOC entry 5270 (class 2606 OID 38925)
-- Name: asignaciones_personal asignaciones_personal_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_pkey PRIMARY KEY (id);


--
-- TOC entry 5211 (class 2606 OID 38927)
-- Name: aulas aulas_nombre_key; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.aulas
    ADD CONSTRAINT aulas_nombre_key UNIQUE (nombre);


--
-- TOC entry 5213 (class 2606 OID 38929)
-- Name: aulas aulas_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.aulas
    ADD CONSTRAINT aulas_pkey PRIMARY KEY (id);


--
-- TOC entry 5272 (class 2606 OID 38931)
-- Name: edicion_videos edicion_videos_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.edicion_videos
    ADD CONSTRAINT edicion_videos_pkey PRIMARY KEY (id);


--
-- TOC entry 5274 (class 2606 OID 38933)
-- Name: equipos equipos_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.equipos
    ADD CONSTRAINT equipos_pkey PRIMARY KEY (id);


--
-- TOC entry 5276 (class 2606 OID 38935)
-- Name: items_paquete_podcast items_paquete_podcast_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.items_paquete_podcast
    ADD CONSTRAINT items_paquete_podcast_pkey PRIMARY KEY (id);


--
-- TOC entry 5215 (class 2606 OID 38937)
-- Name: paquetes_podcast paquetes_podcast_nombre_key; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.paquetes_podcast
    ADD CONSTRAINT paquetes_podcast_nombre_key UNIQUE (nombre);


--
-- TOC entry 5217 (class 2606 OID 38939)
-- Name: paquetes_podcast paquetes_podcast_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.paquetes_podcast
    ADD CONSTRAINT paquetes_podcast_pkey PRIMARY KEY (id);


--
-- TOC entry 5220 (class 2606 OID 38941)
-- Name: reservas_aulas reservas_aulas_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_aulas
    ADD CONSTRAINT reservas_aulas_pkey PRIMARY KEY (id);


--
-- TOC entry 5223 (class 2606 OID 38943)
-- Name: reservas_podcast reservas_podcast_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_podcast
    ADD CONSTRAINT reservas_podcast_pkey PRIMARY KEY (id);


--
-- TOC entry 5279 (class 2606 OID 38945)
-- Name: reservas_radio reservas_radio_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT reservas_radio_pkey PRIMARY KEY (id);


--
-- TOC entry 5284 (class 2606 OID 38947)
-- Name: servicios_produccion servicios_produccion_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.servicios_produccion
    ADD CONSTRAINT servicios_produccion_pkey PRIMARY KEY (id);


--
-- TOC entry 5225 (class 2606 OID 38949)
-- Name: servicios_streaming servicios_streaming_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.servicios_streaming
    ADD CONSTRAINT servicios_streaming_pkey PRIMARY KEY (id);


--
-- TOC entry 5286 (class 2606 OID 38951)
-- Name: tarifas_radio tarifas_radio_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.tarifas_radio
    ADD CONSTRAINT tarifas_radio_pkey PRIMARY KEY (id);


--
-- TOC entry 5293 (class 2606 OID 38953)
-- Name: trabajos_edicion trabajos_edicion_pkey; Type: CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.trabajos_edicion
    ADD CONSTRAINT trabajos_edicion_pkey PRIMARY KEY (id);


--
-- TOC entry 4961 (class 1259 OID 38954)
-- Name: academic_asistencia_taller_estudiantes_asistencia_taller_id_ind; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_asistencia_taller_estudiantes_asistencia_taller_id_ind ON academic.asistencia_taller_estudiantes USING btree (asistencia_taller_id);


--
-- TOC entry 4975 (class 1259 OID 38955)
-- Name: academic_asistencias_talleres_fecha_sesion_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_asistencias_talleres_fecha_sesion_index ON academic.asistencias_talleres USING btree (fecha_sesion);


--
-- TOC entry 4978 (class 1259 OID 38956)
-- Name: academic_asistencias_talleres_taller_id_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_asistencias_talleres_taller_id_index ON academic.asistencias_talleres USING btree (taller_id);


--
-- TOC entry 4984 (class 1259 OID 38957)
-- Name: academic_catalogo_cursos_categoria_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_catalogo_cursos_categoria_index ON academic.catalogo_cursos USING btree (categoria);


--
-- TOC entry 4991 (class 1259 OID 38958)
-- Name: academic_certificados_cedula_impresa_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_certificados_cedula_impresa_index ON academic.certificados USING btree (cedula_impresa);


--
-- TOC entry 4992 (class 1259 OID 38959)
-- Name: academic_certificados_estado_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_certificados_estado_index ON academic.certificados USING btree (estado);


--
-- TOC entry 5018 (class 1259 OID 38960)
-- Name: academic_horarios_dias_dia_semana_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_horarios_dias_dia_semana_index ON academic.horarios_dias USING btree (dia_semana);


--
-- TOC entry 5021 (class 1259 OID 38961)
-- Name: academic_horarios_dias_horario_id_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_horarios_dias_horario_id_index ON academic.horarios_dias USING btree (horario_id);


--
-- TOC entry 5026 (class 1259 OID 38962)
-- Name: academic_horarios_talleres_dia_semana_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_horarios_talleres_dia_semana_index ON academic.horarios_talleres USING btree (dia_semana);


--
-- TOC entry 5027 (class 1259 OID 38963)
-- Name: academic_horarios_talleres_taller_id_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_horarios_talleres_taller_id_index ON academic.horarios_talleres USING btree (taller_id);


--
-- TOC entry 5030 (class 1259 OID 38964)
-- Name: academic_inscripciones_externos_talleres_participante_externo_i; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_inscripciones_externos_talleres_participante_externo_i ON academic.inscripciones_externos_talleres USING btree (participante_externo_id);


--
-- TOC entry 5031 (class 1259 OID 38965)
-- Name: academic_inscripciones_externos_talleres_taller_id_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_inscripciones_externos_talleres_taller_id_index ON academic.inscripciones_externos_talleres USING btree (taller_id);


--
-- TOC entry 5038 (class 1259 OID 38966)
-- Name: academic_inscripciones_talleres_estudiante_id_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_inscripciones_talleres_estudiante_id_index ON academic.inscripciones_talleres USING btree (estudiante_id);


--
-- TOC entry 5041 (class 1259 OID 38967)
-- Name: academic_inscripciones_talleres_taller_id_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_inscripciones_talleres_taller_id_index ON academic.inscripciones_talleres USING btree (taller_id);


--
-- TOC entry 5066 (class 1259 OID 38968)
-- Name: academic_participantes_cursos_personalizados_curso_personalizad; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_participantes_cursos_personalizados_curso_personalizad ON academic.participantes_cursos_personalizados USING btree (curso_personalizado_id);


--
-- TOC entry 5067 (class 1259 OID 38969)
-- Name: academic_participantes_cursos_personalizados_participante_exter; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_participantes_cursos_personalizados_participante_exter ON academic.participantes_cursos_personalizados USING btree (participante_externo_id);


--
-- TOC entry 5072 (class 1259 OID 38970)
-- Name: academic_participantes_externos_email_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_participantes_externos_email_index ON academic.participantes_externos USING btree (email);


--
-- TOC entry 5073 (class 1259 OID 38971)
-- Name: academic_participantes_externos_tipo_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_participantes_externos_tipo_index ON academic.participantes_externos USING btree (tipo);


--
-- TOC entry 5076 (class 1259 OID 38972)
-- Name: academic_solicitudes_inscripcion_created_at_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_solicitudes_inscripcion_created_at_index ON academic.solicitudes_inscripcion USING btree (created_at);


--
-- TOC entry 5077 (class 1259 OID 38973)
-- Name: academic_solicitudes_inscripcion_curso_abierto_id_estado_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_solicitudes_inscripcion_curso_abierto_id_estado_index ON academic.solicitudes_inscripcion USING btree (curso_abierto_id, estado);


--
-- TOC entry 5078 (class 1259 OID 38974)
-- Name: academic_solicitudes_inscripcion_curso_abierto_id_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_solicitudes_inscripcion_curso_abierto_id_index ON academic.solicitudes_inscripcion USING btree (curso_abierto_id);


--
-- TOC entry 5079 (class 1259 OID 38975)
-- Name: academic_solicitudes_inscripcion_estado_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_solicitudes_inscripcion_estado_index ON academic.solicitudes_inscripcion USING btree (estado);


--
-- TOC entry 5080 (class 1259 OID 38976)
-- Name: academic_solicitudes_inscripcion_persona_id_estado_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_solicitudes_inscripcion_persona_id_estado_index ON academic.solicitudes_inscripcion USING btree (persona_id, estado);


--
-- TOC entry 5081 (class 1259 OID 38977)
-- Name: academic_solicitudes_inscripcion_persona_id_index; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX academic_solicitudes_inscripcion_persona_id_index ON academic.solicitudes_inscripcion USING btree (persona_id);


--
-- TOC entry 4970 (class 1259 OID 38978)
-- Name: idx_asistencias_clase; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_asistencias_clase ON academic.asistencias USING btree (clase_id);


--
-- TOC entry 4971 (class 1259 OID 38979)
-- Name: idx_asistencias_clase_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_asistencias_clase_id ON academic.asistencias USING btree (clase_id);


--
-- TOC entry 4972 (class 1259 OID 38980)
-- Name: idx_asistencias_matricula_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_asistencias_matricula_id ON academic.asistencias USING btree (matricula_id);


--
-- TOC entry 4983 (class 1259 OID 38981)
-- Name: idx_cambios_horario_matricula_origen; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_cambios_horario_matricula_origen ON academic.cambios_horario USING btree (matricula_origen_id);


--
-- TOC entry 4989 (class 1259 OID 38982)
-- Name: idx_catalogo_cursos_codigo; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_catalogo_cursos_codigo ON academic.catalogo_cursos USING btree (codigo);


--
-- TOC entry 4990 (class 1259 OID 38983)
-- Name: idx_catalogo_cursos_programa_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_catalogo_cursos_programa_id ON academic.catalogo_cursos USING btree (programa_id);


--
-- TOC entry 4997 (class 1259 OID 39671)
-- Name: idx_certificados_codigo_trgm; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_certificados_codigo_trgm ON academic.certificados USING gin (codigo_certificado public.gin_trgm_ops);


--
-- TOC entry 4998 (class 1259 OID 39673)
-- Name: idx_certificados_codigo_unique; Type: INDEX; Schema: academic; Owner: -
--

CREATE UNIQUE INDEX idx_certificados_codigo_unique ON academic.certificados USING btree (codigo_certificado) WHERE (deleted_at IS NULL);


--
-- TOC entry 4999 (class 1259 OID 39672)
-- Name: idx_certificados_created_at; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_certificados_created_at ON academic.certificados USING btree (created_at);


--
-- TOC entry 5000 (class 1259 OID 38984)
-- Name: idx_certificados_curso_abierto_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_certificados_curso_abierto_id ON academic.certificados USING btree (curso_abierto_id);


--
-- TOC entry 5001 (class 1259 OID 38985)
-- Name: idx_certificados_estudiante_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_certificados_estudiante_id ON academic.certificados USING btree (estudiante_id);


--
-- TOC entry 5004 (class 1259 OID 38986)
-- Name: idx_clases_fecha; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_clases_fecha ON academic.clases USING btree (fecha_clase);


--
-- TOC entry 5005 (class 1259 OID 38987)
-- Name: idx_clases_modulo_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_clases_modulo_id ON academic.clases USING btree (modulo_id);


--
-- TOC entry 5012 (class 1259 OID 38988)
-- Name: idx_cursos_abiertos_catalogo_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_cursos_abiertos_catalogo_id ON academic.cursos_abiertos USING btree (catalogo_curso_id);


--
-- TOC entry 5013 (class 1259 OID 38989)
-- Name: idx_cursos_abiertos_estado; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_cursos_abiertos_estado ON academic.cursos_abiertos USING btree (es_activo);


--
-- TOC entry 5014 (class 1259 OID 38990)
-- Name: idx_cursos_abiertos_resumen; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_cursos_abiertos_resumen ON academic.cursos_abiertos USING btree (estudiantes_inscritos, ingreso_proyectado);


--
-- TOC entry 5015 (class 1259 OID 38991)
-- Name: idx_cursos_estado; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_cursos_estado ON academic.cursos_abiertos USING btree (estado) WHERE (deleted_at IS NULL);


--
-- TOC entry 5024 (class 1259 OID 38992)
-- Name: idx_horarios_dias_dia_semana; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_horarios_dias_dia_semana ON academic.horarios_dias USING btree (dia_semana);


--
-- TOC entry 5025 (class 1259 OID 38993)
-- Name: idx_horarios_dias_horario_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_horarios_dias_horario_id ON academic.horarios_dias USING btree (horario_id);


--
-- TOC entry 5044 (class 1259 OID 38994)
-- Name: idx_matriculas_composite; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_composite ON academic.matriculas USING btree (curso_abierto_id, estado, deleted_at);


--
-- TOC entry 5045 (class 1259 OID 38995)
-- Name: idx_matriculas_curso; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_curso ON academic.matriculas USING btree (curso_abierto_id) WHERE (deleted_at IS NULL);


--
-- TOC entry 5046 (class 1259 OID 38996)
-- Name: idx_matriculas_curso_abierto_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_curso_abierto_id ON academic.matriculas USING btree (curso_abierto_id);


--
-- TOC entry 5047 (class 1259 OID 38997)
-- Name: idx_matriculas_deleted_at; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_deleted_at ON academic.matriculas USING btree (deleted_at);


--
-- TOC entry 5048 (class 1259 OID 38998)
-- Name: idx_matriculas_estado; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_estado ON academic.matriculas USING btree (estado);


--
-- TOC entry 5049 (class 1259 OID 38999)
-- Name: idx_matriculas_estudiante; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_estudiante ON academic.matriculas USING btree (estudiante_id) WHERE (deleted_at IS NULL);


--
-- TOC entry 5050 (class 1259 OID 39000)
-- Name: idx_matriculas_estudiante_estado; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_estudiante_estado ON academic.matriculas USING btree (estudiante_id, estado);


--
-- TOC entry 5051 (class 1259 OID 39001)
-- Name: idx_matriculas_estudiante_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_estudiante_id ON academic.matriculas USING btree (estudiante_id);


--
-- TOC entry 5052 (class 1259 OID 39002)
-- Name: idx_matriculas_solicitud_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_matriculas_solicitud_id ON academic.matriculas USING btree (solicitud_inscripcion_id);


--
-- TOC entry 5059 (class 1259 OID 39003)
-- Name: idx_notas_composite; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_notas_composite ON academic.notas USING btree (matricula_id, modulo_id);


--
-- TOC entry 5060 (class 1259 OID 39004)
-- Name: idx_notas_matricula_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_notas_matricula_id ON academic.notas USING btree (matricula_id);


--
-- TOC entry 5061 (class 1259 OID 39005)
-- Name: idx_notas_modulo_id; Type: INDEX; Schema: academic; Owner: -
--

CREATE INDEX idx_notas_modulo_id ON academic.notas USING btree (modulo_id);


--
-- TOC entry 5092 (class 1259 OID 39006)
-- Name: audit_cambios_horario_auditoria_cambio_horario_id_index; Type: INDEX; Schema: audit; Owner: -
--

CREATE INDEX audit_cambios_horario_auditoria_cambio_horario_id_index ON audit.cambios_horario_auditoria USING btree (cambio_horario_id);


--
-- TOC entry 5093 (class 1259 OID 39007)
-- Name: audit_cambios_horario_auditoria_fecha_cambio_index; Type: INDEX; Schema: audit; Owner: -
--

CREATE INDEX audit_cambios_horario_auditoria_fecha_cambio_index ON audit.cambios_horario_auditoria USING btree (fecha_cambio);


--
-- TOC entry 5094 (class 1259 OID 39008)
-- Name: audit_cambios_horario_auditoria_matricula_origen_id_index; Type: INDEX; Schema: audit; Owner: -
--

CREATE INDEX audit_cambios_horario_auditoria_matricula_origen_id_index ON audit.cambios_horario_auditoria USING btree (matricula_origen_id);


--
-- TOC entry 5099 (class 1259 OID 39009)
-- Name: idx_audit_eventos_financieros_fecha; Type: INDEX; Schema: audit; Owner: -
--

CREATE INDEX idx_audit_eventos_financieros_fecha ON audit.eventos_financieros USING btree (fecha_evento DESC);


--
-- TOC entry 5100 (class 1259 OID 39010)
-- Name: idx_audit_inicios_sesion_fecha; Type: INDEX; Schema: audit; Owner: -
--

CREATE INDEX idx_audit_inicios_sesion_fecha ON audit.inicios_sesion USING btree (fecha_inicio DESC);


--
-- TOC entry 5103 (class 1259 OID 39011)
-- Name: archivos_eliminados_eliminado_por_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX archivos_eliminados_eliminado_por_index ON core.archivos_eliminados USING btree (eliminado_por);


--
-- TOC entry 5104 (class 1259 OID 39012)
-- Name: archivos_eliminados_field_name_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX archivos_eliminados_field_name_index ON core.archivos_eliminados USING btree (field_name);


--
-- TOC entry 5105 (class 1259 OID 39013)
-- Name: archivos_eliminados_model_type_model_id_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX archivos_eliminados_model_type_model_id_index ON core.archivos_eliminados USING btree (model_type, model_id);


--
-- TOC entry 5109 (class 1259 OID 39014)
-- Name: cache_expiration_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX cache_expiration_index ON core.cache USING btree (expiration);


--
-- TOC entry 5112 (class 1259 OID 39015)
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON core.cache_locks USING btree (expiration);


--
-- TOC entry 5121 (class 1259 OID 39016)
-- Name: failed_jobs_connection_queue_failed_at_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON core.failed_jobs USING btree (connection, queue, failed_at);


--
-- TOC entry 5108 (class 1259 OID 39674)
-- Name: idx_archivos_eliminados_lookup; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX idx_archivos_eliminados_lookup ON core.archivos_eliminados USING btree (model_type, model_id, field_name, created_at DESC);


--
-- TOC entry 5130 (class 1259 OID 39017)
-- Name: jobs_queue_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX jobs_queue_index ON core.jobs USING btree (queue);


--
-- TOC entry 5133 (class 1259 OID 39018)
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON core.model_has_permissions USING btree (model_id, model_type);


--
-- TOC entry 5136 (class 1259 OID 39019)
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX model_has_roles_model_id_model_type_index ON core.model_has_roles USING btree (model_id, model_type);


--
-- TOC entry 5151 (class 1259 OID 39020)
-- Name: sessions_last_activity_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX sessions_last_activity_index ON core.sessions USING btree (last_activity);


--
-- TOC entry 5154 (class 1259 OID 39021)
-- Name: sessions_user_id_index; Type: INDEX; Schema: core; Owner: -
--

CREATE INDEX sessions_user_id_index ON core.sessions USING btree (user_id);


--
-- TOC entry 5165 (class 1259 OID 39022)
-- Name: finance_cuentas_por_cobrar_reserva_radio_id_index; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX finance_cuentas_por_cobrar_reserva_radio_id_index ON finance.cuentas_por_cobrar USING btree (reserva_radio_id);


--
-- TOC entry 5088 (class 1259 OID 39023)
-- Name: finance_lineas_pago_modulo_matricula_id_index; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX finance_lineas_pago_modulo_matricula_id_index ON finance.lineas_pago_modulo USING btree (matricula_id);


--
-- TOC entry 5089 (class 1259 OID 39024)
-- Name: finance_lineas_pago_modulo_modulo_id_index; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX finance_lineas_pago_modulo_modulo_id_index ON finance.lineas_pago_modulo USING btree (modulo_id);


--
-- TOC entry 5166 (class 1259 OID 39727)
-- Name: idx_cpc_alquiler_equipo; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cpc_alquiler_equipo ON finance.cuentas_por_cobrar USING btree (alquiler_equipo_id);


--
-- TOC entry 5167 (class 1259 OID 39025)
-- Name: idx_cpc_matricula; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cpc_matricula ON finance.cuentas_por_cobrar USING btree (matricula_id) WHERE (matricula_id IS NOT NULL);


--
-- TOC entry 5168 (class 1259 OID 39026)
-- Name: idx_cpc_produccion; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cpc_produccion ON finance.cuentas_por_cobrar USING btree (servicio_produccion_id) WHERE (servicio_produccion_id IS NOT NULL);


--
-- TOC entry 5169 (class 1259 OID 39027)
-- Name: idx_cpc_reserva_aula; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cpc_reserva_aula ON finance.cuentas_por_cobrar USING btree (reserva_aula_id) WHERE (reserva_aula_id IS NOT NULL);


--
-- TOC entry 5170 (class 1259 OID 39028)
-- Name: idx_cpc_reserva_podcast; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cpc_reserva_podcast ON finance.cuentas_por_cobrar USING btree (reserva_podcast_id) WHERE (reserva_podcast_id IS NOT NULL);


--
-- TOC entry 5171 (class 1259 OID 39029)
-- Name: idx_cpc_streaming; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cpc_streaming ON finance.cuentas_por_cobrar USING btree (servicio_streaming_id) WHERE (servicio_streaming_id IS NOT NULL);


--
-- TOC entry 5172 (class 1259 OID 39719)
-- Name: idx_cuentas_alquiler_equipo_id; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cuentas_alquiler_equipo_id ON finance.cuentas_por_cobrar USING btree (alquiler_equipo_id);


--
-- TOC entry 5173 (class 1259 OID 39722)
-- Name: idx_cuentas_asesoria_id; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cuentas_asesoria_id ON finance.cuentas_por_cobrar USING btree (asesoria_id);


--
-- TOC entry 5174 (class 1259 OID 39721)
-- Name: idx_cuentas_clase_extra_id; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cuentas_clase_extra_id ON finance.cuentas_por_cobrar USING btree (clase_extra_id);


--
-- TOC entry 5175 (class 1259 OID 39720)
-- Name: idx_cuentas_edicion_video_id; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cuentas_edicion_video_id ON finance.cuentas_por_cobrar USING btree (edicion_video_id);


--
-- TOC entry 5176 (class 1259 OID 39716)
-- Name: idx_cuentas_estado; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cuentas_estado ON finance.cuentas_por_cobrar USING btree (estado);


--
-- TOC entry 5177 (class 1259 OID 39718)
-- Name: idx_cuentas_inscripcion_taller_id; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cuentas_inscripcion_taller_id ON finance.cuentas_por_cobrar USING btree (inscripcion_taller_id);


--
-- TOC entry 5178 (class 1259 OID 39717)
-- Name: idx_cuentas_solicitud_inscripcion_id; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_cuentas_solicitud_inscripcion_id ON finance.cuentas_por_cobrar USING btree (solicitud_inscripcion_id);


--
-- TOC entry 5184 (class 1259 OID 39030)
-- Name: idx_egresos_fecha; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_egresos_fecha ON finance.transacciones_egreso USING btree (fecha_pago DESC);


--
-- TOC entry 5181 (class 1259 OID 39031)
-- Name: idx_horas_instructor_pago; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_horas_instructor_pago ON finance.horas_instructor USING btree (instructor_id, pagado);


--
-- TOC entry 5187 (class 1259 OID 39032)
-- Name: idx_ingresos_fecha; Type: INDEX; Schema: finance; Owner: -
--

CREATE INDEX idx_ingresos_fecha ON finance.transacciones_ingreso USING btree (fecha_pago DESC);


--
-- TOC entry 5198 (class 1259 OID 39033)
-- Name: idx_staff_asistencia_fecha; Type: INDEX; Schema: ops; Owner: -
--

CREATE INDEX idx_staff_asistencia_fecha ON ops.registro_asistencia_staff USING btree (persona_id, fecha);


--
-- TOC entry 5203 (class 1259 OID 39034)
-- Name: idx_tareas_staff_estado; Type: INDEX; Schema: ops; Owner: -
--

CREATE INDEX idx_tareas_staff_estado ON ops.tareas_staff USING btree (estado);


--
-- TOC entry 5204 (class 1259 OID 39035)
-- Name: idx_tareas_staff_persona; Type: INDEX; Schema: ops; Owner: -
--

CREATE INDEX idx_tareas_staff_persona ON ops.tareas_staff USING btree (persona_id);


--
-- TOC entry 5207 (class 1259 OID 39036)
-- Name: idx_clientes_externos_apellidos; Type: INDEX; Schema: people; Owner: -
--

CREATE INDEX idx_clientes_externos_apellidos ON people.clientes_externos USING gin (apellidos public.gin_trgm_ops);


--
-- TOC entry 5208 (class 1259 OID 39037)
-- Name: idx_clientes_externos_cedula; Type: INDEX; Schema: people; Owner: -
--

CREATE INDEX idx_clientes_externos_cedula ON people.clientes_externos USING btree (cedula);


--
-- TOC entry 5209 (class 1259 OID 39038)
-- Name: idx_clientes_externos_nombres; Type: INDEX; Schema: people; Owner: -
--

CREATE INDEX idx_clientes_externos_nombres ON people.clientes_externos USING gin (nombres public.gin_trgm_ops);


--
-- TOC entry 5190 (class 1259 OID 39039)
-- Name: idx_personas_apellidos_trgm; Type: INDEX; Schema: people; Owner: -
--

CREATE INDEX idx_personas_apellidos_trgm ON people.personas USING gin (apellidos public.gin_trgm_ops);


--
-- TOC entry 5191 (class 1259 OID 39040)
-- Name: idx_personas_cedula; Type: INDEX; Schema: people; Owner: -
--

CREATE INDEX idx_personas_cedula ON people.personas USING btree (cedula) WHERE (deleted_at IS NULL);


--
-- TOC entry 5192 (class 1259 OID 39041)
-- Name: idx_personas_nombres_trgm; Type: INDEX; Schema: people; Owner: -
--

CREATE INDEX idx_personas_nombres_trgm ON people.personas USING gin (nombres public.gin_trgm_ops);


--
-- TOC entry 5193 (class 1259 OID 39042)
-- Name: idx_personas_tipo; Type: INDEX; Schema: people; Owner: -
--

CREATE INDEX idx_personas_tipo ON people.personas USING btree (tipo) WHERE (deleted_at IS NULL);


--
-- TOC entry 5266 (class 1259 OID 39726)
-- Name: idx_alquiler_equipos_cliente_externo; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX idx_alquiler_equipos_cliente_externo ON services.alquiler_equipos USING btree (cliente_externo_id);


--
-- TOC entry 5218 (class 1259 OID 39724)
-- Name: idx_reservas_aulas_cliente_externo; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX idx_reservas_aulas_cliente_externo ON services.reservas_aulas USING btree (cliente_externo_id);


--
-- TOC entry 5221 (class 1259 OID 39725)
-- Name: idx_reservas_podcast_cliente_externo; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX idx_reservas_podcast_cliente_externo ON services.reservas_podcast USING btree (cliente_externo_id);


--
-- TOC entry 5277 (class 1259 OID 39723)
-- Name: idx_reservas_radio_cliente_externo; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX idx_reservas_radio_cliente_externo ON services.reservas_radio USING btree (cliente_externo_id);


--
-- TOC entry 5267 (class 1259 OID 39043)
-- Name: services_alquiler_equipos_equipo_id_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_alquiler_equipos_equipo_id_index ON services.alquiler_equipos USING btree (equipo_id);


--
-- TOC entry 5268 (class 1259 OID 39044)
-- Name: services_alquiler_equipos_estado_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_alquiler_equipos_estado_index ON services.alquiler_equipos USING btree (estado);


--
-- TOC entry 5280 (class 1259 OID 39045)
-- Name: services_reservas_radio_estado_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_reservas_radio_estado_index ON services.reservas_radio USING btree (estado);


--
-- TOC entry 5281 (class 1259 OID 39046)
-- Name: services_reservas_radio_fecha_reserva_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_reservas_radio_fecha_reserva_index ON services.reservas_radio USING btree (fecha_reserva);


--
-- TOC entry 5282 (class 1259 OID 39047)
-- Name: services_reservas_radio_operador_id_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_reservas_radio_operador_id_index ON services.reservas_radio USING btree (operador_id);


--
-- TOC entry 5287 (class 1259 OID 39699)
-- Name: services_trabajos_edicion_cliente_externo_id_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_trabajos_edicion_cliente_externo_id_index ON services.trabajos_edicion USING btree (cliente_externo_id);


--
-- TOC entry 5288 (class 1259 OID 39048)
-- Name: services_trabajos_edicion_estado_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_trabajos_edicion_estado_index ON services.trabajos_edicion USING btree (estado);


--
-- TOC entry 5289 (class 1259 OID 39049)
-- Name: services_trabajos_edicion_fecha_limite_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_trabajos_edicion_fecha_limite_index ON services.trabajos_edicion USING btree (fecha_limite);


--
-- TOC entry 5290 (class 1259 OID 39050)
-- Name: services_trabajos_edicion_fecha_recibo_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_trabajos_edicion_fecha_recibo_index ON services.trabajos_edicion USING btree (fecha_recibo);


--
-- TOC entry 5291 (class 1259 OID 39705)
-- Name: services_trabajos_edicion_persona_id_index; Type: INDEX; Schema: services; Owner: -
--

CREATE INDEX services_trabajos_edicion_persona_id_index ON services.trabajos_edicion USING btree (persona_id);


--
-- TOC entry 5419 (class 2620 OID 39051)
-- Name: matriculas trg_actualizar_perfil_estudiante; Type: TRIGGER; Schema: academic; Owner: -
--

CREATE TRIGGER trg_actualizar_perfil_estudiante AFTER INSERT OR UPDATE ON academic.matriculas FOR EACH ROW EXECUTE FUNCTION academic.fn_actualizar_perfil_estudiante();

ALTER TABLE academic.matriculas DISABLE TRIGGER trg_actualizar_perfil_estudiante;


--
-- TOC entry 5420 (class 2620 OID 39052)
-- Name: matriculas trg_actualizar_resumen_curso; Type: TRIGGER; Schema: academic; Owner: -
--

CREATE TRIGGER trg_actualizar_resumen_curso AFTER INSERT OR DELETE OR UPDATE ON academic.matriculas FOR EACH ROW EXECUTE FUNCTION academic.fn_actualizar_resumen_curso();

ALTER TABLE academic.matriculas DISABLE TRIGGER trg_actualizar_resumen_curso;


--
-- TOC entry 5418 (class 2620 OID 39691)
-- Name: cambios_horario trg_auditar_cambios_horario; Type: TRIGGER; Schema: academic; Owner: -
--

CREATE TRIGGER trg_auditar_cambios_horario AFTER INSERT OR DELETE OR UPDATE ON academic.cambios_horario FOR EACH ROW EXECUTE FUNCTION audit.fn_auditar_cambios_horario();


--
-- TOC entry 5421 (class 2620 OID 39675)
-- Name: matriculas trg_validar_capacidad; Type: TRIGGER; Schema: academic; Owner: -
--

CREATE TRIGGER trg_validar_capacidad BEFORE INSERT ON academic.matriculas FOR EACH ROW EXECUTE FUNCTION academic.fn_validar_capacidad_curso();


--
-- TOC entry 5423 (class 2620 OID 39055)
-- Name: transacciones_ingreso trg_actualizar_saldo; Type: TRIGGER; Schema: finance; Owner: -
--

CREATE TRIGGER trg_actualizar_saldo AFTER INSERT OR DELETE OR UPDATE ON finance.transacciones_ingreso FOR EACH ROW EXECUTE FUNCTION finance.fn_actualizar_cuenta_cobrar();


--
-- TOC entry 5422 (class 2620 OID 39056)
-- Name: transacciones_egreso trg_resumen_caja_egreso; Type: TRIGGER; Schema: finance; Owner: -
--

CREATE TRIGGER trg_resumen_caja_egreso AFTER INSERT OR DELETE OR UPDATE ON finance.transacciones_egreso FOR EACH ROW EXECUTE FUNCTION finance.fn_registrar_movimiento_caja();


--
-- TOC entry 5424 (class 2620 OID 39057)
-- Name: transacciones_ingreso trg_resumen_caja_ingreso; Type: TRIGGER; Schema: finance; Owner: -
--

CREATE TRIGGER trg_resumen_caja_ingreso AFTER INSERT OR DELETE OR UPDATE ON finance.transacciones_ingreso FOR EACH ROW EXECUTE FUNCTION finance.fn_registrar_movimiento_caja();


--
-- TOC entry 5425 (class 2620 OID 39058)
-- Name: personas trg_personas_updated_at; Type: TRIGGER; Schema: people; Owner: -
--

CREATE TRIGGER trg_personas_updated_at BEFORE UPDATE ON people.personas FOR EACH ROW EXECUTE FUNCTION core.fn_set_updated_at();


--
-- TOC entry 5297 (class 2606 OID 39059)
-- Name: asistencia_taller_estudiantes academic_asistencia_taller_estudiantes_asistencia_taller_id_for; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencia_taller_estudiantes
    ADD CONSTRAINT academic_asistencia_taller_estudiantes_asistencia_taller_id_for FOREIGN KEY (asistencia_taller_id) REFERENCES academic.asistencias_talleres(id) ON DELETE CASCADE;


--
-- TOC entry 5298 (class 2606 OID 39064)
-- Name: asistencia_taller_estudiantes academic_asistencia_taller_estudiantes_inscripcion_taller_id_fo; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencia_taller_estudiantes
    ADD CONSTRAINT academic_asistencia_taller_estudiantes_inscripcion_taller_id_fo FOREIGN KEY (inscripcion_taller_id) REFERENCES academic.inscripciones_taller(id) ON DELETE CASCADE;


--
-- TOC entry 5299 (class 2606 OID 39069)
-- Name: asistencia_taller_estudiantes academic_asistencia_taller_estudiantes_participante_externo_id_; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencia_taller_estudiantes
    ADD CONSTRAINT academic_asistencia_taller_estudiantes_participante_externo_id_ FOREIGN KEY (participante_externo_id) REFERENCES academic.participantes_externos(id) ON DELETE CASCADE;


--
-- TOC entry 5302 (class 2606 OID 39074)
-- Name: asistencias_talleres academic_asistencias_talleres_taller_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencias_talleres
    ADD CONSTRAINT academic_asistencias_talleres_taller_id_foreign FOREIGN KEY (taller_id) REFERENCES academic.talleres(id) ON DELETE CASCADE;


--
-- TOC entry 5322 (class 2606 OID 39079)
-- Name: horarios_talleres academic_horarios_talleres_taller_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.horarios_talleres
    ADD CONSTRAINT academic_horarios_talleres_taller_id_foreign FOREIGN KEY (taller_id) REFERENCES academic.talleres(id) ON DELETE CASCADE;


--
-- TOC entry 5323 (class 2606 OID 39084)
-- Name: inscripciones_externos_talleres academic_inscripciones_externos_talleres_participante_externo_i; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_externos_talleres
    ADD CONSTRAINT academic_inscripciones_externos_talleres_participante_externo_i FOREIGN KEY (participante_externo_id) REFERENCES academic.participantes_externos(id) ON DELETE CASCADE;


--
-- TOC entry 5324 (class 2606 OID 39089)
-- Name: inscripciones_externos_talleres academic_inscripciones_externos_talleres_taller_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_externos_talleres
    ADD CONSTRAINT academic_inscripciones_externos_talleres_taller_id_foreign FOREIGN KEY (taller_id) REFERENCES academic.talleres(id) ON DELETE CASCADE;


--
-- TOC entry 5327 (class 2606 OID 39094)
-- Name: inscripciones_talleres academic_inscripciones_talleres_estudiante_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_talleres
    ADD CONSTRAINT academic_inscripciones_talleres_estudiante_id_foreign FOREIGN KEY (estudiante_id) REFERENCES people.personas(id) ON DELETE CASCADE;


--
-- TOC entry 5328 (class 2606 OID 39099)
-- Name: inscripciones_talleres academic_inscripciones_talleres_taller_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_talleres
    ADD CONSTRAINT academic_inscripciones_talleres_taller_id_foreign FOREIGN KEY (taller_id) REFERENCES academic.talleres(id) ON DELETE CASCADE;


--
-- TOC entry 5329 (class 2606 OID 39104)
-- Name: matriculas academic_matriculas_solicitud_inscripcion_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT academic_matriculas_solicitud_inscripcion_id_foreign FOREIGN KEY (solicitud_inscripcion_id) REFERENCES academic.solicitudes_inscripcion(id) ON DELETE SET NULL;


--
-- TOC entry 5335 (class 2606 OID 39109)
-- Name: participantes_cursos_personalizados academic_participantes_cursos_personalizados_curso_personalizad; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.participantes_cursos_personalizados
    ADD CONSTRAINT academic_participantes_cursos_personalizados_curso_personalizad FOREIGN KEY (curso_personalizado_id) REFERENCES academic.cursos_abiertos(id) ON DELETE CASCADE;


--
-- TOC entry 5336 (class 2606 OID 39114)
-- Name: participantes_cursos_personalizados academic_participantes_cursos_personalizados_participante_exter; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.participantes_cursos_personalizados
    ADD CONSTRAINT academic_participantes_cursos_personalizados_participante_exter FOREIGN KEY (participante_externo_id) REFERENCES academic.participantes_externos(id) ON DELETE CASCADE;


--
-- TOC entry 5337 (class 2606 OID 39119)
-- Name: solicitudes_inscripcion academic_solicitudes_inscripcion_curso_abierto_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT academic_solicitudes_inscripcion_curso_abierto_id_foreign FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id) ON DELETE CASCADE;


--
-- TOC entry 5338 (class 2606 OID 39124)
-- Name: solicitudes_inscripcion academic_solicitudes_inscripcion_participante_externo_id_foreig; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT academic_solicitudes_inscripcion_participante_externo_id_foreig FOREIGN KEY (participante_externo_id) REFERENCES people.clientes_externos(id) ON DELETE CASCADE;


--
-- TOC entry 5339 (class 2606 OID 39129)
-- Name: solicitudes_inscripcion academic_solicitudes_inscripcion_persona_id_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT academic_solicitudes_inscripcion_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE CASCADE;


--
-- TOC entry 5340 (class 2606 OID 39134)
-- Name: solicitudes_inscripcion academic_solicitudes_inscripcion_validado_por_foreign; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.solicitudes_inscripcion
    ADD CONSTRAINT academic_solicitudes_inscripcion_validado_por_foreign FOREIGN KEY (validado_por) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5294 (class 2606 OID 39139)
-- Name: asesorias asesorias_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asesorias
    ADD CONSTRAINT asesorias_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5295 (class 2606 OID 39144)
-- Name: asesorias asesorias_instructor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asesorias
    ADD CONSTRAINT asesorias_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5296 (class 2606 OID 39149)
-- Name: asesorias asesorias_persona_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asesorias
    ADD CONSTRAINT asesorias_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5300 (class 2606 OID 39154)
-- Name: asistencias asistencias_clase_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencias
    ADD CONSTRAINT asistencias_clase_id_fkey FOREIGN KEY (clase_id) REFERENCES academic.clases(id) ON DELETE CASCADE;


--
-- TOC entry 5301 (class 2606 OID 39159)
-- Name: asistencias asistencias_matricula_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.asistencias
    ADD CONSTRAINT asistencias_matricula_id_fkey FOREIGN KEY (matricula_id) REFERENCES academic.matriculas(id) ON DELETE CASCADE;


--
-- TOC entry 5303 (class 2606 OID 39164)
-- Name: cambios_horario cambios_horario_autorizado_por_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cambios_horario
    ADD CONSTRAINT cambios_horario_autorizado_por_fkey FOREIGN KEY (autorizado_por) REFERENCES people.personas(id);


--
-- TOC entry 5304 (class 2606 OID 39681)
-- Name: cambios_horario cambios_horario_curso_abierto_nuevo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cambios_horario
    ADD CONSTRAINT cambios_horario_curso_abierto_nuevo_id_fkey FOREIGN KEY (curso_abierto_nuevo_id) REFERENCES academic.cursos_abiertos(id) ON DELETE RESTRICT;


--
-- TOC entry 5305 (class 2606 OID 39676)
-- Name: cambios_horario cambios_horario_matricula_origen_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cambios_horario
    ADD CONSTRAINT cambios_horario_matricula_origen_id_fkey FOREIGN KEY (matricula_origen_id) REFERENCES academic.matriculas(id) ON DELETE CASCADE;


--
-- TOC entry 5306 (class 2606 OID 39179)
-- Name: certificados certificados_catalogo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_catalogo_id_fkey FOREIGN KEY (catalogo_id) REFERENCES academic.catalogo_cursos(id);


--
-- TOC entry 5307 (class 2606 OID 39184)
-- Name: certificados certificados_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5308 (class 2606 OID 39189)
-- Name: certificados certificados_estudiante_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES people.personas(id);


--
-- TOC entry 5309 (class 2606 OID 39194)
-- Name: certificados certificados_modulo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.certificados
    ADD CONSTRAINT certificados_modulo_id_fkey FOREIGN KEY (modulo_id) REFERENCES academic.modulos(id);


--
-- TOC entry 5312 (class 2606 OID 39199)
-- Name: clases_extras clases_extras_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.clases_extras
    ADD CONSTRAINT clases_extras_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5313 (class 2606 OID 39204)
-- Name: clases_extras clases_extras_estudiante_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.clases_extras
    ADD CONSTRAINT clases_extras_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES people.personas(id);


--
-- TOC entry 5314 (class 2606 OID 39209)
-- Name: clases_extras clases_extras_instructor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.clases_extras
    ADD CONSTRAINT clases_extras_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5310 (class 2606 OID 39214)
-- Name: clases clases_instructor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.clases
    ADD CONSTRAINT clases_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5311 (class 2606 OID 39219)
-- Name: clases clases_modulo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.clases
    ADD CONSTRAINT clases_modulo_id_fkey FOREIGN KEY (modulo_id) REFERENCES academic.modulos(id) ON DELETE CASCADE;


--
-- TOC entry 5315 (class 2606 OID 39224)
-- Name: comentarios_curso comentarios_curso_autor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.comentarios_curso
    ADD CONSTRAINT comentarios_curso_autor_id_fkey FOREIGN KEY (autor_id) REFERENCES people.personas(id);


--
-- TOC entry 5316 (class 2606 OID 39229)
-- Name: comentarios_curso comentarios_curso_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.comentarios_curso
    ADD CONSTRAINT comentarios_curso_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5317 (class 2606 OID 39234)
-- Name: cursos_abiertos cursos_abiertos_catalogo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_catalogo_id_fkey FOREIGN KEY (catalogo_curso_id) REFERENCES academic.catalogo_cursos(id);


--
-- TOC entry 5318 (class 2606 OID 39239)
-- Name: cursos_abiertos cursos_abiertos_ciudad_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_ciudad_id_fkey FOREIGN KEY (ciudad_id) REFERENCES core.ciudades(id);


--
-- TOC entry 5319 (class 2606 OID 39244)
-- Name: cursos_abiertos cursos_abiertos_docente_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_docente_id_fkey FOREIGN KEY (docente_id) REFERENCES people.personas(id);


--
-- TOC entry 5320 (class 2606 OID 39249)
-- Name: cursos_abiertos cursos_abiertos_horario_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_horario_id_fkey FOREIGN KEY (horario_id) REFERENCES academic.horarios(id);


--
-- TOC entry 5321 (class 2606 OID 39254)
-- Name: cursos_abiertos cursos_abiertos_instructor_titular_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.cursos_abiertos
    ADD CONSTRAINT cursos_abiertos_instructor_titular_id_fkey FOREIGN KEY (instructor_titular_id) REFERENCES people.personas(id);


--
-- TOC entry 5325 (class 2606 OID 39259)
-- Name: inscripciones_taller inscripciones_taller_persona_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_taller
    ADD CONSTRAINT inscripciones_taller_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5326 (class 2606 OID 39264)
-- Name: inscripciones_taller inscripciones_taller_taller_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.inscripciones_taller
    ADD CONSTRAINT inscripciones_taller_taller_id_fkey FOREIGN KEY (taller_id) REFERENCES academic.talleres(id);


--
-- TOC entry 5330 (class 2606 OID 39269)
-- Name: matriculas matriculas_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT matriculas_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5331 (class 2606 OID 39274)
-- Name: matriculas matriculas_estudiante_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.matriculas
    ADD CONSTRAINT matriculas_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES people.personas(id);


--
-- TOC entry 5332 (class 2606 OID 39279)
-- Name: modulos modulos_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.modulos
    ADD CONSTRAINT modulos_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id) ON DELETE CASCADE;


--
-- TOC entry 5333 (class 2606 OID 39284)
-- Name: notas notas_matricula_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.notas
    ADD CONSTRAINT notas_matricula_id_fkey FOREIGN KEY (matricula_id) REFERENCES academic.matriculas(id) ON DELETE CASCADE;


--
-- TOC entry 5334 (class 2606 OID 39289)
-- Name: notas notas_modulo_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.notas
    ADD CONSTRAINT notas_modulo_id_fkey FOREIGN KEY (modulo_id) REFERENCES academic.modulos(id);


--
-- TOC entry 5341 (class 2606 OID 39294)
-- Name: talleres talleres_ciudad_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.talleres
    ADD CONSTRAINT talleres_ciudad_id_fkey FOREIGN KEY (ciudad_id) REFERENCES core.ciudades(id);


--
-- TOC entry 5342 (class 2606 OID 39299)
-- Name: talleres talleres_instructor_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.talleres
    ADD CONSTRAINT talleres_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5343 (class 2606 OID 39304)
-- Name: traslados_modulo traslados_modulo_autorizado_por_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_autorizado_por_fkey FOREIGN KEY (autorizado_por) REFERENCES people.personas(id);


--
-- TOC entry 5344 (class 2606 OID 39309)
-- Name: traslados_modulo traslados_modulo_curso_abierto_destino_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_curso_abierto_destino_id_fkey FOREIGN KEY (curso_abierto_destino_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5345 (class 2606 OID 39686)
-- Name: traslados_modulo traslados_modulo_matricula_origen_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_matricula_origen_id_fkey FOREIGN KEY (matricula_origen_id) REFERENCES academic.matriculas(id) ON DELETE CASCADE;


--
-- TOC entry 5346 (class 2606 OID 39319)
-- Name: traslados_modulo traslados_modulo_modulo_destino_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_modulo_destino_id_fkey FOREIGN KEY (modulo_destino_id) REFERENCES academic.modulos(id);


--
-- TOC entry 5347 (class 2606 OID 39324)
-- Name: traslados_modulo traslados_modulo_modulo_origen_id_fkey; Type: FK CONSTRAINT; Schema: academic; Owner: -
--

ALTER TABLE ONLY academic.traslados_modulo
    ADD CONSTRAINT traslados_modulo_modulo_origen_id_fkey FOREIGN KEY (modulo_origen_id) REFERENCES academic.modulos(id);


--
-- TOC entry 5351 (class 2606 OID 39329)
-- Name: eventos_financieros eventos_financieros_registrado_por_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.eventos_financieros
    ADD CONSTRAINT eventos_financieros_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES people.personas(id);


--
-- TOC entry 5352 (class 2606 OID 39334)
-- Name: eventos_financieros eventos_financieros_transaccion_egreso_id_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.eventos_financieros
    ADD CONSTRAINT eventos_financieros_transaccion_egreso_id_fkey FOREIGN KEY (transaccion_egreso_id) REFERENCES finance.transacciones_egreso(id) ON DELETE CASCADE;


--
-- TOC entry 5353 (class 2606 OID 39339)
-- Name: eventos_financieros eventos_financieros_transaccion_ingreso_id_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.eventos_financieros
    ADD CONSTRAINT eventos_financieros_transaccion_ingreso_id_fkey FOREIGN KEY (transaccion_ingreso_id) REFERENCES finance.transacciones_ingreso(id) ON DELETE CASCADE;


--
-- TOC entry 5354 (class 2606 OID 39344)
-- Name: inicios_sesion inicios_sesion_cuenta_id_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.inicios_sesion
    ADD CONSTRAINT inicios_sesion_cuenta_id_fkey FOREIGN KEY (cuenta_id) REFERENCES people.cuentas_sistema(id) ON DELETE SET NULL;


--
-- TOC entry 5355 (class 2606 OID 39349)
-- Name: inicios_sesion inicios_sesion_persona_id_fkey; Type: FK CONSTRAINT; Schema: audit; Owner: -
--

ALTER TABLE ONLY audit.inicios_sesion
    ADD CONSTRAINT inicios_sesion_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5356 (class 2606 OID 39354)
-- Name: model_has_permissions core_model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.model_has_permissions
    ADD CONSTRAINT core_model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES core.permissions(id) ON DELETE CASCADE;


--
-- TOC entry 5357 (class 2606 OID 39359)
-- Name: model_has_roles core_model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.model_has_roles
    ADD CONSTRAINT core_model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES core.roles(id) ON DELETE CASCADE;


--
-- TOC entry 5358 (class 2606 OID 39364)
-- Name: role_has_permissions core_role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.role_has_permissions
    ADD CONSTRAINT core_role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES core.permissions(id) ON DELETE CASCADE;


--
-- TOC entry 5359 (class 2606 OID 39369)
-- Name: role_has_permissions core_role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.role_has_permissions
    ADD CONSTRAINT core_role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES core.roles(id) ON DELETE CASCADE;


--
-- TOC entry 5360 (class 2606 OID 39374)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_asesoria_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_asesoria_id_fkey FOREIGN KEY (asesoria_id) REFERENCES academic.asesorias(id);


--
-- TOC entry 5361 (class 2606 OID 39379)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_clase_extra_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_clase_extra_id_fkey FOREIGN KEY (clase_extra_id) REFERENCES academic.clases_extras(id);


--
-- TOC entry 5362 (class 2606 OID 39389)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_inscripcion_taller_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_inscripcion_taller_id_fkey FOREIGN KEY (inscripcion_taller_id) REFERENCES academic.inscripciones_taller(id);


--
-- TOC entry 5363 (class 2606 OID 39394)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_matricula_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_matricula_id_fkey FOREIGN KEY (matricula_id) REFERENCES academic.matriculas(id);


--
-- TOC entry 5364 (class 2606 OID 39399)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_reserva_aula_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_reserva_aula_id_fkey FOREIGN KEY (reserva_aula_id) REFERENCES services.reservas_aulas(id);


--
-- TOC entry 5365 (class 2606 OID 39404)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_reserva_podcast_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_reserva_podcast_id_fkey FOREIGN KEY (reserva_podcast_id) REFERENCES services.reservas_podcast(id);


--
-- TOC entry 5366 (class 2606 OID 39409)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_servicio_produccion_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_servicio_produccion_id_fkey FOREIGN KEY (servicio_produccion_id) REFERENCES services.servicios_produccion(id);


--
-- TOC entry 5367 (class 2606 OID 39414)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_servicio_streaming_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_servicio_streaming_id_fkey FOREIGN KEY (servicio_streaming_id) REFERENCES services.servicios_streaming(id);


--
-- TOC entry 5368 (class 2606 OID 39419)
-- Name: cuentas_por_cobrar finance_cuentas_por_cobrar_alquiler_equipo_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT finance_cuentas_por_cobrar_alquiler_equipo_id_foreign FOREIGN KEY (alquiler_equipo_id) REFERENCES services.alquiler_equipos(id) ON DELETE SET NULL;


--
-- TOC entry 5369 (class 2606 OID 39706)
-- Name: cuentas_por_cobrar finance_cuentas_por_cobrar_edicion_video_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT finance_cuentas_por_cobrar_edicion_video_id_foreign FOREIGN KEY (edicion_video_id) REFERENCES services.trabajos_edicion(id) ON DELETE SET NULL;


--
-- TOC entry 5370 (class 2606 OID 39424)
-- Name: cuentas_por_cobrar finance_cuentas_por_cobrar_reserva_radio_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT finance_cuentas_por_cobrar_reserva_radio_id_foreign FOREIGN KEY (reserva_radio_id) REFERENCES services.reservas_radio(id) ON DELETE SET NULL;


--
-- TOC entry 5371 (class 2606 OID 39429)
-- Name: cuentas_por_cobrar finance_cuentas_por_cobrar_solicitud_inscripcion_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.cuentas_por_cobrar
    ADD CONSTRAINT finance_cuentas_por_cobrar_solicitud_inscripcion_id_foreign FOREIGN KEY (solicitud_inscripcion_id) REFERENCES academic.solicitudes_inscripcion(id) ON DELETE SET NULL;


--
-- TOC entry 5348 (class 2606 OID 39434)
-- Name: lineas_pago_modulo finance_lineas_pago_modulo_ajustado_por_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.lineas_pago_modulo
    ADD CONSTRAINT finance_lineas_pago_modulo_ajustado_por_foreign FOREIGN KEY (ajustado_por) REFERENCES people.personas(id);


--
-- TOC entry 5349 (class 2606 OID 39439)
-- Name: lineas_pago_modulo finance_lineas_pago_modulo_matricula_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.lineas_pago_modulo
    ADD CONSTRAINT finance_lineas_pago_modulo_matricula_id_foreign FOREIGN KEY (matricula_id) REFERENCES academic.matriculas(id) ON DELETE CASCADE;


--
-- TOC entry 5350 (class 2606 OID 39444)
-- Name: lineas_pago_modulo finance_lineas_pago_modulo_modulo_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.lineas_pago_modulo
    ADD CONSTRAINT finance_lineas_pago_modulo_modulo_id_foreign FOREIGN KEY (modulo_id) REFERENCES academic.modulos(id) ON DELETE RESTRICT;


--
-- TOC entry 5378 (class 2606 OID 39449)
-- Name: transacciones_ingreso finance_transacciones_ingreso_linea_pago_modulo_id_foreign; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.transacciones_ingreso
    ADD CONSTRAINT finance_transacciones_ingreso_linea_pago_modulo_id_foreign FOREIGN KEY (linea_pago_modulo_id) REFERENCES finance.lineas_pago_modulo(id);


--
-- TOC entry 5372 (class 2606 OID 39454)
-- Name: horas_instructor horas_instructor_clase_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_clase_id_fkey FOREIGN KEY (clase_id) REFERENCES academic.clases(id);


--
-- TOC entry 5373 (class 2606 OID 39459)
-- Name: horas_instructor horas_instructor_curso_abierto_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_curso_abierto_id_fkey FOREIGN KEY (curso_abierto_id) REFERENCES academic.cursos_abiertos(id);


--
-- TOC entry 5374 (class 2606 OID 39464)
-- Name: horas_instructor horas_instructor_egreso_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_egreso_id_fkey FOREIGN KEY (egreso_id) REFERENCES finance.transacciones_egreso(id);


--
-- TOC entry 5375 (class 2606 OID 39469)
-- Name: horas_instructor horas_instructor_instructor_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.horas_instructor
    ADD CONSTRAINT horas_instructor_instructor_id_fkey FOREIGN KEY (instructor_id) REFERENCES people.personas(id);


--
-- TOC entry 5376 (class 2606 OID 39474)
-- Name: transacciones_egreso transacciones_egreso_categoria_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.transacciones_egreso
    ADD CONSTRAINT transacciones_egreso_categoria_id_fkey FOREIGN KEY (categoria_id) REFERENCES finance.categorias_egreso(id);


--
-- TOC entry 5377 (class 2606 OID 39479)
-- Name: transacciones_egreso transacciones_egreso_registrado_por_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.transacciones_egreso
    ADD CONSTRAINT transacciones_egreso_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES people.personas(id);


--
-- TOC entry 5379 (class 2606 OID 39484)
-- Name: transacciones_ingreso transacciones_ingreso_cuenta_cobrar_id_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.transacciones_ingreso
    ADD CONSTRAINT transacciones_ingreso_cuenta_cobrar_id_fkey FOREIGN KEY (cuenta_cobrar_id) REFERENCES finance.cuentas_por_cobrar(id) ON DELETE RESTRICT;


--
-- TOC entry 5380 (class 2606 OID 39489)
-- Name: transacciones_ingreso transacciones_ingreso_registrado_por_fkey; Type: FK CONSTRAINT; Schema: finance; Owner: -
--

ALTER TABLE ONLY finance.transacciones_ingreso
    ADD CONSTRAINT transacciones_ingreso_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES people.personas(id);


--
-- TOC entry 5382 (class 2606 OID 39494)
-- Name: registro_asistencia_staff registro_asistencia_staff_persona_id_fkey; Type: FK CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.registro_asistencia_staff
    ADD CONSTRAINT registro_asistencia_staff_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5383 (class 2606 OID 39499)
-- Name: registro_asistencia_staff registro_asistencia_staff_registrado_por_fkey; Type: FK CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.registro_asistencia_staff
    ADD CONSTRAINT registro_asistencia_staff_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES people.personas(id);


--
-- TOC entry 5384 (class 2606 OID 39504)
-- Name: clientes_externos clientes_externos_ciudad_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.clientes_externos
    ADD CONSTRAINT clientes_externos_ciudad_id_fkey FOREIGN KEY (ciudad_id) REFERENCES core.ciudades(id);


--
-- TOC entry 5393 (class 2606 OID 39509)
-- Name: cuentas_sistema cuentas_sistema_persona_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.cuentas_sistema
    ADD CONSTRAINT cuentas_sistema_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5394 (class 2606 OID 39514)
-- Name: perfil_estudiante perfil_estudiante_persona_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_estudiante
    ADD CONSTRAINT perfil_estudiante_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5395 (class 2606 OID 39519)
-- Name: perfil_instructor perfil_instructor_persona_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_instructor
    ADD CONSTRAINT perfil_instructor_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5396 (class 2606 OID 39524)
-- Name: perfil_staff perfil_staff_persona_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.perfil_staff
    ADD CONSTRAINT perfil_staff_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5381 (class 2606 OID 39529)
-- Name: personas personas_ciudad_id_fkey; Type: FK CONSTRAINT; Schema: people; Owner: -
--

ALTER TABLE ONLY people.personas
    ADD CONSTRAINT personas_ciudad_id_fkey FOREIGN KEY (ciudad_id) REFERENCES core.ciudades(id);


--
-- TOC entry 5400 (class 2606 OID 39539)
-- Name: asignaciones_personal asignaciones_personal_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5401 (class 2606 OID 39544)
-- Name: asignaciones_personal asignaciones_personal_reserva_podcast_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_reserva_podcast_id_fkey FOREIGN KEY (reserva_podcast_id) REFERENCES services.reservas_podcast(id);


--
-- TOC entry 5402 (class 2606 OID 39549)
-- Name: asignaciones_personal asignaciones_personal_servicio_produccion_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_servicio_produccion_id_fkey FOREIGN KEY (servicio_produccion_id) REFERENCES services.servicios_produccion(id);


--
-- TOC entry 5403 (class 2606 OID 39554)
-- Name: asignaciones_personal asignaciones_personal_servicio_streaming_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT asignaciones_personal_servicio_streaming_id_fkey FOREIGN KEY (servicio_streaming_id) REFERENCES services.servicios_streaming(id);


--
-- TOC entry 5406 (class 2606 OID 39559)
-- Name: edicion_videos edicion_videos_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.edicion_videos
    ADD CONSTRAINT edicion_videos_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5407 (class 2606 OID 39564)
-- Name: edicion_videos edicion_videos_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.edicion_videos
    ADD CONSTRAINT edicion_videos_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5408 (class 2606 OID 39569)
-- Name: items_paquete_podcast items_paquete_podcast_paquete_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.items_paquete_podcast
    ADD CONSTRAINT items_paquete_podcast_paquete_id_fkey FOREIGN KEY (paquete_id) REFERENCES services.paquetes_podcast(id);


--
-- TOC entry 5385 (class 2606 OID 39574)
-- Name: reservas_aulas reservas_aulas_aula_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_aulas
    ADD CONSTRAINT reservas_aulas_aula_id_fkey FOREIGN KEY (aula_id) REFERENCES services.aulas(id);


--
-- TOC entry 5386 (class 2606 OID 39579)
-- Name: reservas_aulas reservas_aulas_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_aulas
    ADD CONSTRAINT reservas_aulas_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5387 (class 2606 OID 39584)
-- Name: reservas_aulas reservas_aulas_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_aulas
    ADD CONSTRAINT reservas_aulas_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5388 (class 2606 OID 39589)
-- Name: reservas_podcast reservas_podcast_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_podcast
    ADD CONSTRAINT reservas_podcast_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5389 (class 2606 OID 39594)
-- Name: reservas_podcast reservas_podcast_paquete_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_podcast
    ADD CONSTRAINT reservas_podcast_paquete_id_fkey FOREIGN KEY (paquete_id) REFERENCES services.paquetes_podcast(id);


--
-- TOC entry 5390 (class 2606 OID 39599)
-- Name: reservas_podcast reservas_podcast_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_podcast
    ADD CONSTRAINT reservas_podcast_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5397 (class 2606 OID 39604)
-- Name: alquiler_equipos services_alquiler_equipos_cliente_externo_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.alquiler_equipos
    ADD CONSTRAINT services_alquiler_equipos_cliente_externo_id_foreign FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id) ON DELETE SET NULL;


--
-- TOC entry 5398 (class 2606 OID 39609)
-- Name: alquiler_equipos services_alquiler_equipos_equipo_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.alquiler_equipos
    ADD CONSTRAINT services_alquiler_equipos_equipo_id_foreign FOREIGN KEY (equipo_id) REFERENCES services.equipos(id);


--
-- TOC entry 5399 (class 2606 OID 39614)
-- Name: alquiler_equipos services_alquiler_equipos_persona_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.alquiler_equipos
    ADD CONSTRAINT services_alquiler_equipos_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5404 (class 2606 OID 39711)
-- Name: asignaciones_personal services_asignaciones_personal_edicion_video_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT services_asignaciones_personal_edicion_video_id_foreign FOREIGN KEY (edicion_video_id) REFERENCES services.trabajos_edicion(id) ON DELETE SET NULL;


--
-- TOC entry 5405 (class 2606 OID 39619)
-- Name: asignaciones_personal services_asignaciones_personal_reserva_radio_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.asignaciones_personal
    ADD CONSTRAINT services_asignaciones_personal_reserva_radio_id_foreign FOREIGN KEY (reserva_radio_id) REFERENCES services.reservas_radio(id) ON DELETE CASCADE;


--
-- TOC entry 5409 (class 2606 OID 39624)
-- Name: reservas_radio services_reservas_radio_cliente_externo_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT services_reservas_radio_cliente_externo_id_foreign FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id) ON DELETE SET NULL;


--
-- TOC entry 5410 (class 2606 OID 39629)
-- Name: reservas_radio services_reservas_radio_operador_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT services_reservas_radio_operador_id_foreign FOREIGN KEY (operador_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5411 (class 2606 OID 39634)
-- Name: reservas_radio services_reservas_radio_persona_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT services_reservas_radio_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5412 (class 2606 OID 39639)
-- Name: reservas_radio services_reservas_radio_tarifa_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.reservas_radio
    ADD CONSTRAINT services_reservas_radio_tarifa_id_foreign FOREIGN KEY (tarifa_id) REFERENCES services.tarifas_radio(id);


--
-- TOC entry 5415 (class 2606 OID 39694)
-- Name: trabajos_edicion services_trabajos_edicion_cliente_externo_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.trabajos_edicion
    ADD CONSTRAINT services_trabajos_edicion_cliente_externo_id_foreign FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id) ON DELETE SET NULL;


--
-- TOC entry 5416 (class 2606 OID 39700)
-- Name: trabajos_edicion services_trabajos_edicion_persona_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.trabajos_edicion
    ADD CONSTRAINT services_trabajos_edicion_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES people.personas(id) ON DELETE SET NULL;


--
-- TOC entry 5417 (class 2606 OID 39644)
-- Name: trabajos_edicion services_trabajos_edicion_reserva_podcast_id_foreign; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.trabajos_edicion
    ADD CONSTRAINT services_trabajos_edicion_reserva_podcast_id_foreign FOREIGN KEY (reserva_podcast_id) REFERENCES services.reservas_podcast(id) ON DELETE SET NULL;


--
-- TOC entry 5413 (class 2606 OID 39649)
-- Name: servicios_produccion servicios_produccion_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.servicios_produccion
    ADD CONSTRAINT servicios_produccion_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5414 (class 2606 OID 39654)
-- Name: servicios_produccion servicios_produccion_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.servicios_produccion
    ADD CONSTRAINT servicios_produccion_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


--
-- TOC entry 5391 (class 2606 OID 39659)
-- Name: servicios_streaming servicios_streaming_cliente_externo_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.servicios_streaming
    ADD CONSTRAINT servicios_streaming_cliente_externo_id_fkey FOREIGN KEY (cliente_externo_id) REFERENCES people.clientes_externos(id);


--
-- TOC entry 5392 (class 2606 OID 39664)
-- Name: servicios_streaming servicios_streaming_persona_id_fkey; Type: FK CONSTRAINT; Schema: services; Owner: -
--

ALTER TABLE ONLY services.servicios_streaming
    ADD CONSTRAINT servicios_streaming_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES people.personas(id);


-- Completed on 2026-07-17 15:31:50 -05

--
-- PostgreSQL database dump complete
--

\unrestrict 9dDCa2NhvQqvcQooWp1qVWwLKWuaCbojFTA4VVtP1gM3dWJPBXyPj68u1iDt2jB

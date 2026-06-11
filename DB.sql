-- =============================================================================
-- 1. SCHEMAS Y EXTENSIONES
-- =============================================================================
CREATE SCHEMA IF NOT EXISTS core;
CREATE SCHEMA IF NOT EXISTS people;
CREATE SCHEMA IF NOT EXISTS academic;
CREATE SCHEMA IF NOT EXISTS services;
CREATE SCHEMA IF NOT EXISTS finance;
CREATE SCHEMA IF NOT EXISTS ops;
CREATE SCHEMA IF NOT EXISTS audit;

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "unaccent";

-- =============================================================================
-- 2. TIPOS ENUMERADOS
-- =============================================================================
CREATE TYPE academic.t_estado_oferta    AS ENUM ('pendiente','confirmado','en_progreso','completado','cancelado');
CREATE TYPE academic.t_estado_matricula AS ENUM ('activo','completado','retirado','reprobado');
CREATE TYPE finance.t_metodo_pago       AS ENUM ('efectivo','transferencia','deposito','tarjeta','otro');
CREATE TYPE finance.t_estado_pago       AS ENUM ('pendiente','abonado','pagado','anulado');
CREATE TYPE services.t_estado_reserva   AS ENUM ('reservado','confirmado','en_progreso','completado','cancelado');

-- =============================================================================
-- 3. CORE SCHEMA
-- =============================================================================
CREATE TABLE core.ciudades (
    id      BIGSERIAL PRIMARY KEY,
    nombre  VARCHAR(100) UNIQUE NOT NULL
);

-- =============================================================================
-- 4. PEOPLE SCHEMA
-- =============================================================================
CREATE TABLE people.personas (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tipo                VARCHAR(50),
    cedula              VARCHAR(20) UNIQUE,
    nombres             VARCHAR(100) NOT NULL,
    apellidos           VARCHAR(100) NOT NULL,
    correo              VARCHAR(150),
    celular             VARCHAR(20),
    ciudad_id           BIGINT REFERENCES core.ciudades(id),
    cedula_photo_url    VARCHAR(500),
    es_activo           BOOLEAN DEFAULT TRUE,
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    updated_at          TIMESTAMPTZ DEFAULT NOW(),
    deleted_at          TIMESTAMPTZ
);

CREATE TABLE people.clientes_externos (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nombres         VARCHAR(100) NOT NULL,
    apellidos       VARCHAR(100),
    cedula          VARCHAR(20),
    correo          VARCHAR(150),
    celular         VARCHAR(20),
    ciudad_id       BIGINT REFERENCES core.ciudades(id),
    observaciones   TEXT,
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE people.perfil_estudiante (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id          UUID NOT NULL UNIQUE REFERENCES people.personas(id),
    fecha_nacimiento    DATE,
    notas_internas      TEXT,
    primera_matricula   DATE,
    ultima_matricula    DATE,
    total_cursos        INT DEFAULT 0
);

CREATE TABLE people.perfil_instructor (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id      UUID NOT NULL UNIQUE REFERENCES people.personas(id),
    especialidad    VARCHAR(200),
    bio             TEXT
);

CREATE TABLE people.perfil_staff (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id      UUID NOT NULL UNIQUE REFERENCES people.personas(id),
    cargo           VARCHAR(100) NOT NULL,
    salario_base    NUMERIC(10,2),
    fecha_ingreso   DATE,
    es_pasante      BOOLEAN DEFAULT FALSE
);

CREATE TABLE people.cuentas_sistema (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id      UUID NOT NULL UNIQUE REFERENCES people.personas(id),
    username        VARCHAR(100) UNIQUE NOT NULL,
    password_hash   VARCHAR(500) NOT NULL,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    last_login      TIMESTAMPTZ
);

-- =============================================================================
-- 5. ACADEMIC SCHEMA
-- =============================================================================
CREATE TABLE academic.catalogo_cursos (
    id                   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    categoria            VARCHAR(50) NOT NULL CHECK (categoria IN ('regular','personalizado','taller')),
    nombre               VARCHAR(200) NOT NULL,
    descripcion          TEXT,
    modulos_default      SMALLINT DEFAULT 2,
    duracion_horas_total INT
);

CREATE TABLE academic.horarios (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nombre_referencial  VARCHAR(100) NOT NULL,
    dia_semana          SMALLINT[] NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME NOT NULL,
    es_activo           BOOLEAN DEFAULT TRUE
);

CREATE TABLE academic.cursos_abiertos (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    catalogo_id             UUID NOT NULL REFERENCES academic.catalogo_cursos(id),
    instructor_titular_id   UUID REFERENCES people.personas(id),
    ciudad_id               BIGINT REFERENCES core.ciudades(id),
    horario_id              UUID REFERENCES academic.horarios(id),
    modalidad               VARCHAR(50) NOT NULL CHECK (modalidad IN ('presencial','virtual')),
    capacidad_maxima        SMALLINT NOT NULL DEFAULT 12,
    precio_base             NUMERIC(10,2) NOT NULL,
    estudiantes_inscritos   INT NOT NULL DEFAULT 0,
    ingreso_proyectado      NUMERIC(12,2) NOT NULL DEFAULT 0,
    fecha_inicio            DATE,
    fecha_fin_estimada      DATE,
    estado                  academic.t_estado_oferta DEFAULT 'pendiente',
    created_at              TIMESTAMPTZ DEFAULT NOW(),
    deleted_at              TIMESTAMPTZ
);

CREATE TABLE academic.talleres (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nombre              VARCHAR(200) NOT NULL,
    descripcion         TEXT,
    instructor_id       UUID REFERENCES people.personas(id),
    ciudad_id           BIGINT REFERENCES core.ciudades(id),
    modalidad           VARCHAR(50) NOT NULL CHECK (modalidad IN ('presencial','virtual')),
    capacidad_maxima    SMALLINT NOT NULL DEFAULT 30,
    precio              NUMERIC(10,2) NOT NULL,
    fecha               DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME NOT NULL,
    abierto_externos    BOOLEAN DEFAULT TRUE,
    estado              academic.t_estado_oferta DEFAULT 'pendiente',
    created_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE academic.inscripciones_taller (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    taller_id           UUID NOT NULL REFERENCES academic.talleres(id),
    persona_id          UUID NOT NULL REFERENCES people.personas(id),
    precio_pagado       NUMERIC(10,2) NOT NULL,
    estado              academic.t_estado_matricula DEFAULT 'activo',
    fecha_inscripcion   TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT uq_persona_taller UNIQUE (taller_id, persona_id)
);

CREATE TABLE academic.modulos (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    curso_abierto_id    UUID NOT NULL REFERENCES academic.cursos_abiertos(id) ON DELETE CASCADE,
    nombre_modulo       VARCHAR(100) NOT NULL,
    numero_orden        SMALLINT NOT NULL,
    fecha_inicio        DATE,
    fecha_fin           DATE
);

CREATE TABLE academic.clases (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    modulo_id       UUID NOT NULL REFERENCES academic.modulos(id) ON DELETE CASCADE,
    instructor_id   UUID REFERENCES people.personas(id),
    fecha_clase     DATE NOT NULL,
    hora_inicio     TIME NOT NULL,
    hora_fin        TIME NOT NULL,
    observaciones   TEXT
);

CREATE TABLE academic.clases_extras (
    id               UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    estudiante_id    UUID NOT NULL REFERENCES people.personas(id),
    instructor_id    UUID REFERENCES people.personas(id),
    curso_abierto_id UUID REFERENCES academic.cursos_abiertos(id),
    fecha_clase      DATE NOT NULL,
    hora_inicio      TIME NOT NULL,
    hora_fin         TIME NOT NULL,
    motivo           TEXT,
    precio           NUMERIC(10,2) NOT NULL DEFAULT 0,
    created_at       TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE academic.matriculas (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    estudiante_id       UUID NOT NULL REFERENCES people.personas(id),
    curso_abierto_id    UUID NOT NULL REFERENCES academic.cursos_abiertos(id),
    precio_total        NUMERIC(10,2) NOT NULL,
    tipo_pago           VARCHAR(20) NOT NULL DEFAULT 'completo' CHECK (tipo_pago IN ('completo','bono')),
    voucher_url         VARCHAR(500),
    estado              academic.t_estado_matricula DEFAULT 'activo',
    fecha_inscripcion   TIMESTAMPTZ DEFAULT NOW(),
    deleted_at          TIMESTAMPTZ,
    CONSTRAINT uq_estudiante_curso UNIQUE (estudiante_id, curso_abierto_id)
);

CREATE TABLE academic.cambios_horario (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    matricula_origen_id     UUID NOT NULL REFERENCES academic.matriculas(id),
    curso_abierto_nuevo_id  UUID NOT NULL REFERENCES academic.cursos_abiertos(id),
    motivo                  TEXT,
    autorizado_por          UUID REFERENCES people.personas(id),
    fecha_cambio            TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE academic.traslados_modulo (
    id                       UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    matricula_origen_id      UUID NOT NULL REFERENCES academic.matriculas(id),
    modulo_origen_id         UUID NOT NULL REFERENCES academic.modulos(id),
    curso_abierto_destino_id UUID NOT NULL REFERENCES academic.cursos_abiertos(id),
    modulo_destino_id        UUID NOT NULL REFERENCES academic.modulos(id),
    motivo                   TEXT,
    autorizado_por           UUID REFERENCES people.personas(id),
    fecha_traslado           TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE academic.asistencias (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    matricula_id    UUID NOT NULL REFERENCES academic.matriculas(id) ON DELETE CASCADE,
    clase_id        UUID NOT NULL REFERENCES academic.clases(id) ON DELETE CASCADE,
    asistio         BOOLEAN DEFAULT FALSE,
    CONSTRAINT uq_asistencia UNIQUE (matricula_id, clase_id)
);

CREATE TABLE academic.notas (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    matricula_id    UUID NOT NULL REFERENCES academic.matriculas(id) ON DELETE CASCADE,
    modulo_id       UUID NOT NULL REFERENCES academic.modulos(id),
    nota            NUMERIC(4,2) CHECK (nota BETWEEN 0 AND 10),
    aprobado        BOOLEAN,
    CONSTRAINT uq_nota_modulo UNIQUE (matricula_id, modulo_id)
);

CREATE TABLE academic.certificados (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    estudiante_id       UUID NOT NULL REFERENCES people.personas(id),
    catalogo_id         UUID NOT NULL REFERENCES academic.catalogo_cursos(id),
    curso_abierto_id    UUID REFERENCES academic.cursos_abiertos(id),
    modulo_id           UUID REFERENCES academic.modulos(id),
    cedula_impresa      VARCHAR(20) NOT NULL,
    fecha_emision       DATE DEFAULT CURRENT_DATE,
    codigo_certificado  VARCHAR(100) UNIQUE NOT NULL
);

CREATE TABLE academic.comentarios_curso (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    curso_abierto_id    UUID NOT NULL REFERENCES academic.cursos_abiertos(id),
    autor_id            UUID NOT NULL REFERENCES people.personas(id),
    comentario          TEXT NOT NULL,
    calificacion        SMALLINT CHECK (calificacion BETWEEN 1 AND 5),
    es_publico          BOOLEAN DEFAULT FALSE,
    created_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE academic.asesorias (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id          UUID REFERENCES people.personas(id),
    cliente_externo_id  UUID REFERENCES people.clientes_externos(id),
    instructor_id       UUID NOT NULL REFERENCES people.personas(id),
    titulo              VARCHAR(200) NOT NULL,
    descripcion         TEXT,
    modalidad           VARCHAR(50) NOT NULL CHECK (modalidad IN ('presencial','virtual')),
    fecha               DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME NOT NULL,
    notas_sesion        TEXT,
    precio              NUMERIC(10,2) NOT NULL DEFAULT 0,
    estado              services.t_estado_reserva DEFAULT 'reservado',
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT chk_asesoria_cliente CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
);

-- =============================================================================
-- 6. SERVICES SCHEMA
-- =============================================================================
CREATE TABLE services.aulas (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nombre          VARCHAR(100) UNIQUE NOT NULL,
    capacidad       SMALLINT NOT NULL,
    precio_hora     NUMERIC(10,2) NOT NULL,
    caracteristicas TEXT
);

CREATE TABLE services.reservas_aulas (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    aula_id             UUID NOT NULL REFERENCES services.aulas(id),
    persona_id          UUID REFERENCES people.personas(id),
    cliente_externo_id  UUID REFERENCES people.clientes_externos(id),
    fecha_reserva       DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME NOT NULL,
    precio_total        NUMERIC(10,2) NOT NULL,
    estado              services.t_estado_reserva DEFAULT 'reservado',
    CONSTRAINT chk_cliente_aula CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
);

CREATE TABLE services.paquetes_podcast (
    id          SERIAL PRIMARY KEY,
    nombre      VARCHAR(100) UNIQUE NOT NULL,
    descripcion TEXT,
    precio_base NUMERIC(10,2) NOT NULL,
    es_activo   BOOLEAN DEFAULT TRUE
);

CREATE TABLE services.items_paquete_podcast (
    id          SERIAL PRIMARY KEY,
    paquete_id  INT NOT NULL REFERENCES services.paquetes_podcast(id),
    descripcion VARCHAR(200) NOT NULL
);

CREATE TABLE services.reservas_podcast (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id          UUID REFERENCES people.personas(id),
    cliente_externo_id  UUID REFERENCES people.clientes_externos(id),
    paquete_id          INT NOT NULL REFERENCES services.paquetes_podcast(id),
    fecha_reserva       DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME NOT NULL,
    precio_total        NUMERIC(10,2) NOT NULL,
    observaciones       TEXT,
    estado              services.t_estado_reserva DEFAULT 'reservado',
    CONSTRAINT chk_cliente_podcast CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
);

CREATE TABLE services.servicios_streaming (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id          UUID REFERENCES people.personas(id),
    cliente_externo_id  UUID REFERENCES people.clientes_externos(id),
    fecha_evento        DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME NOT NULL,
    lugar               VARCHAR(300) NOT NULL,
    descripcion         TEXT,
    precio_total        NUMERIC(10,2) NOT NULL,
    estado              services.t_estado_reserva DEFAULT 'reservado',
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT chk_cliente_streaming CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
);

CREATE TABLE services.servicios_produccion (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id          UUID REFERENCES people.personas(id),
    cliente_externo_id  UUID REFERENCES people.clientes_externos(id),
    fecha_evento        DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME NOT NULL,
    lugar               VARCHAR(300) NOT NULL,
    descripcion         TEXT,
    precio_total        NUMERIC(10,2) NOT NULL,
    estado              services.t_estado_reserva DEFAULT 'reservado',
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT chk_cliente_produccion CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
);

CREATE TABLE services.edicion_videos (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id          UUID REFERENCES people.personas(id),
    cliente_externo_id  UUID REFERENCES people.clientes_externos(id),
    fecha_recepcion     DATE NOT NULL,
    fecha_entrega       DATE NOT NULL,
    descripcion         TEXT,
    precio_total        NUMERIC(10,2) NOT NULL,
    estado              services.t_estado_reserva DEFAULT 'reservado',
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT chk_cliente_edicion CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
);

CREATE TABLE services.asignaciones_personal (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id              UUID NOT NULL REFERENCES people.personas(id),
    reserva_podcast_id      UUID REFERENCES services.reservas_podcast(id),
    servicio_streaming_id   UUID REFERENCES services.servicios_streaming(id),
    servicio_produccion_id  UUID REFERENCES services.servicios_produccion(id),
    edicion_video_id        UUID REFERENCES services.edicion_videos(id),
    rol_en_servicio         VARCHAR(100),
    CONSTRAINT chk_una_sola_asignacion CHECK (
        num_nonnulls(reserva_podcast_id, servicio_streaming_id, servicio_produccion_id, edicion_video_id) = 1
    )
);

CREATE TABLE services.equipos (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nombre          VARCHAR(200) NOT NULL,
    descripcion     TEXT,
    foto_url        VARCHAR(500),
    precio_diario   NUMERIC(10,2) NOT NULL DEFAULT 0,
    estado          VARCHAR(20) NOT NULL DEFAULT 'disponible'
                    CHECK (estado IN ('disponible','alquilado','mantenimiento')),
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE services.alquiler_equipos (
    id                       UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    equipo_id                UUID NOT NULL REFERENCES services.equipos(id),
    persona_id               UUID REFERENCES people.personas(id),
    cliente_externo_id       UUID REFERENCES people.clientes_externos(id),
    fecha_entrega            TIMESTAMPTZ NOT NULL,
    fecha_devolucion_esperada TIMESTAMPTZ NOT NULL,
    fecha_recepcion          TIMESTAMPTZ,
    foto_salida_url          VARCHAR(500),
    foto_retorno_url         VARCHAR(500),
    observaciones            TEXT,
    precio_total             NUMERIC(10,2) NOT NULL,
    estado                   VARCHAR(20) NOT NULL DEFAULT 'activo'
                             CHECK (estado IN ('activo','devuelto','vencido')),
    created_at               TIMESTAMPTZ DEFAULT NOW(),
    updated_at               TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT chk_cliente_equipo CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
);

-- =============================================================================
-- 7. FINANCE SCHEMA
-- =============================================================================
CREATE TABLE finance.cuentas_por_cobrar (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    matricula_id            UUID REFERENCES academic.matriculas(id),
    inscripcion_taller_id   UUID REFERENCES academic.inscripciones_taller(id),
    reserva_aula_id         UUID REFERENCES services.reservas_aulas(id),
    reserva_podcast_id      UUID REFERENCES services.reservas_podcast(id),
    servicio_streaming_id   UUID REFERENCES services.servicios_streaming(id),
    servicio_produccion_id  UUID REFERENCES services.servicios_produccion(id),
    edicion_video_id        UUID REFERENCES services.edicion_videos(id),
    alquiler_equipo_id      UUID REFERENCES services.alquiler_equipos(id),
    clase_extra_id          UUID REFERENCES academic.clases_extras(id),
    asesoria_id             UUID REFERENCES academic.asesorias(id),
    CONSTRAINT chk_un_origen CHECK (
        num_nonnulls(
            matricula_id,
            inscripcion_taller_id,
            reserva_aula_id,
            reserva_podcast_id,
            servicio_streaming_id,
            servicio_produccion_id,
            edicion_video_id,
            alquiler_equipo_id,
            clase_extra_id,
            asesoria_id
        ) = 1
    ),
    monto_total         NUMERIC(10,2) NOT NULL,
    monto_abonado       NUMERIC(10,2) DEFAULT 0,
    saldo_pendiente     NUMERIC(10,2) GENERATED ALWAYS AS (monto_total - monto_abonado) STORED,
    estado              finance.t_estado_pago DEFAULT 'pendiente',
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    updated_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE finance.transacciones_ingreso (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    cuenta_cobrar_id    UUID NOT NULL REFERENCES finance.cuentas_por_cobrar(id) ON DELETE RESTRICT,
    monto               NUMERIC(10,2) NOT NULL CHECK (monto > 0),
    metodo_pago         finance.t_metodo_pago NOT NULL,
    comprobante_url     VARCHAR(500),
    fecha_pago          TIMESTAMPTZ DEFAULT NOW(),
    registrado_por      UUID REFERENCES people.personas(id)
);

CREATE TABLE finance.categorias_egreso (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(100) UNIQUE NOT NULL,
    tipo_general    VARCHAR(50)
);

CREATE TABLE finance.transacciones_egreso (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    categoria_id    INT NOT NULL REFERENCES finance.categorias_egreso(id),
    descripcion     TEXT NOT NULL,
    monto           NUMERIC(10,2) NOT NULL CHECK (monto > 0),
    comprobante_url VARCHAR(500),
    fecha_pago      TIMESTAMPTZ DEFAULT NOW(),
    registrado_por  UUID REFERENCES people.personas(id)
);

CREATE TABLE finance.horas_instructor (
    id                UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    instructor_id     UUID NOT NULL REFERENCES people.personas(id),
    clase_id          UUID REFERENCES academic.clases(id),
    curso_abierto_id  UUID REFERENCES academic.cursos_abiertos(id),
    fecha             DATE NOT NULL,
    horas_trabajadas  NUMERIC(4,2) NOT NULL CHECK (horas_trabajadas > 0),
    tarifa_aplicada   NUMERIC(10,2) NOT NULL,
    monto_a_pagar     NUMERIC(10,2) GENERATED ALWAYS AS (horas_trabajadas * tarifa_aplicada) STORED,
    pagado            BOOLEAN DEFAULT FALSE,
    egreso_id         UUID REFERENCES finance.transacciones_egreso(id)
);

CREATE TABLE finance.resumen_caja (
    id              SMALLINT PRIMARY KEY DEFAULT 1,
    total_ingresos  NUMERIC(14,2) NOT NULL DEFAULT 0,
    total_egresos   NUMERIC(14,2) NOT NULL DEFAULT 0,
    saldo_actual    NUMERIC(14,2) NOT NULL DEFAULT 0,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_resumen_caja_singleton CHECK (id = 1)
);

INSERT INTO finance.resumen_caja (id, total_ingresos, total_egresos, saldo_actual)
VALUES (1, 0, 0, 0)
ON CONFLICT (id) DO NOTHING;

-- =============================================================================
-- 8. OPS SCHEMA
-- =============================================================================
CREATE TABLE ops.registro_asistencia_staff (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    persona_id      UUID NOT NULL REFERENCES people.personas(id),
    fecha           DATE NOT NULL,
    hora_entrada    TIME,
    hora_salida     TIME,
    actividades     TEXT,
    observaciones   TEXT,
    registrado_por  UUID REFERENCES people.personas(id),
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT uq_staff_dia UNIQUE (persona_id, fecha)
);

-- =============================================================================
-- 9. AUDITORÍA
-- =============================================================================
CREATE TABLE audit.inicios_sesion (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    cuenta_id       UUID REFERENCES people.cuentas_sistema(id) ON DELETE SET NULL,
    persona_id      UUID REFERENCES people.personas(id) ON DELETE SET NULL,
    username        VARCHAR(100),
    ip_address      INET,
    user_agent      TEXT,
    fecha_inicio    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    exito           BOOLEAN NOT NULL DEFAULT TRUE,
    observaciones   TEXT
);

CREATE TABLE audit.eventos_financieros (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tipo_evento             VARCHAR(20) NOT NULL CHECK (tipo_evento IN ('INGRESO','EGRESO')),
    transaccion_ingreso_id  UUID REFERENCES finance.transacciones_ingreso(id) ON DELETE CASCADE,
    transaccion_egreso_id   UUID REFERENCES finance.transacciones_egreso(id) ON DELETE CASCADE,
    monto                   NUMERIC(10,2) NOT NULL,
    descripcion             TEXT,
    fecha_evento            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    registrado_por          UUID REFERENCES people.personas(id),
    saldo_resultante        NUMERIC(14,2) NOT NULL DEFAULT 0,
    CONSTRAINT chk_evento_financiero_origen CHECK (
        num_nonnulls(transaccion_ingreso_id, transaccion_egreso_id) = 1
    )
);

-- =============================================================================
-- 10. VISTAS DE NEGOCIO
-- =============================================================================
CREATE OR REPLACE VIEW ops.vista_agenda_unificada AS
SELECT
    'CLASE_CURSO'   AS tipo_evento,
    c.id            AS referencia_id,
    'Clase: ' || cc.nombre AS titulo,
    c.fecha_clase   AS fecha,
    c.hora_inicio,
    c.hora_fin,
    p.nombres || ' ' || p.apellidos AS responsable
FROM academic.clases c
JOIN academic.modulos m          ON c.modulo_id = m.id
JOIN academic.cursos_abiertos ca ON m.curso_abierto_id = ca.id
JOIN academic.catalogo_cursos cc ON ca.catalogo_id = cc.id
LEFT JOIN people.personas p      ON c.instructor_id = p.id
UNION ALL
SELECT
    'TALLER',
    t.id,
    'Taller: ' || t.nombre,
    t.fecha,
    t.hora_inicio,
    t.hora_fin,
    p.nombres || ' ' || p.apellidos
FROM academic.talleres t
LEFT JOIN people.personas p ON t.instructor_id = p.id
UNION ALL
SELECT
    'ALQUILER_AULA',
    ra.id,
    'Aula: ' || a.nombre,
    ra.fecha_reserva,
    ra.hora_inicio,
    ra.hora_fin,
    COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) AS responsable
FROM services.reservas_aulas ra
JOIN services.aulas a ON ra.aula_id = a.id
LEFT JOIN people.personas pp ON ra.persona_id = pp.id
LEFT JOIN people.clientes_externos ce ON ra.cliente_externo_id = ce.id
UNION ALL
SELECT
    'PODCAST',
    rp.id,
    'Podcast: ' || ppq.nombre,
    rp.fecha_reserva,
    rp.hora_inicio,
    rp.hora_fin,
    COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) AS responsable
FROM services.reservas_podcast rp
JOIN services.paquetes_podcast ppq ON rp.paquete_id = ppq.id
LEFT JOIN people.personas pp ON rp.persona_id = pp.id
LEFT JOIN people.clientes_externos ce ON rp.cliente_externo_id = ce.id
UNION ALL
SELECT
    'STREAMING',
    ss.id,
    'Streaming: ' || COALESCE(ss.descripcion, 'Servicio de streaming'),
    ss.fecha_evento,
    ss.hora_inicio,
    ss.hora_fin,
    COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) AS responsable
FROM services.servicios_streaming ss
LEFT JOIN people.personas pp ON ss.persona_id = pp.id
LEFT JOIN people.clientes_externos ce ON ss.cliente_externo_id = ce.id
UNION ALL
SELECT
    'ASESORIA',
    as2.id,
    'Asesoría: ' || as2.titulo,
    as2.fecha,
    as2.hora_inicio,
    as2.hora_fin,
    pi.nombres || ' ' || pi.apellidos
FROM academic.asesorias as2
JOIN people.personas pi ON as2.instructor_id = pi.id;

CREATE OR REPLACE VIEW finance.vista_balance_mensual AS
SELECT
    EXTRACT(YEAR  FROM fecha_pago) AS anio,
    EXTRACT(MONTH FROM fecha_pago) AS mes,
    'INGRESO'                      AS tipo_flujo,
    SUM(monto)                     AS total_movimiento
FROM finance.transacciones_ingreso
GROUP BY anio, mes
UNION ALL
SELECT
    EXTRACT(YEAR  FROM fecha_pago),
    EXTRACT(MONTH FROM fecha_pago),
    'EGRESO',
    SUM(monto)
FROM finance.transacciones_egreso
GROUP BY EXTRACT(YEAR FROM fecha_pago), EXTRACT(MONTH FROM fecha_pago);

CREATE OR REPLACE VIEW finance.vista_horas_instructor AS
SELECT
    p.id AS instructor_id,
    p.nombres || ' ' || p.apellidos AS instructor,
    COUNT(*)                        AS total_registros,
    SUM(hi.horas_trabajadas)        AS total_horas,
    SUM(hi.monto_a_pagar)           AS total_a_pagar,
    SUM(hi.monto_a_pagar) FILTER (WHERE hi.pagado = FALSE) AS pendiente_pago
FROM finance.horas_instructor hi
JOIN people.personas p ON hi.instructor_id = p.id
GROUP BY p.id, p.nombres, p.apellidos;

CREATE OR REPLACE VIEW academic.vista_cursos_finanzas AS
SELECT
    ca.id,
    cc.nombre AS curso,
    ca.modalidad,
    ca.precio_base,
    ca.capacidad_maxima,
    ca.estudiantes_inscritos,
    ca.ingreso_proyectado,
    COALESCE(SUM(m.precio_total) FILTER (WHERE m.deleted_at IS NULL), 0) AS ingreso_matriculado_real
FROM academic.cursos_abiertos ca
JOIN academic.catalogo_cursos cc ON cc.id = ca.catalogo_id
LEFT JOIN academic.matriculas m ON m.curso_abierto_id = ca.id
GROUP BY ca.id, cc.nombre, ca.modalidad, ca.precio_base, ca.capacidad_maxima, ca.estudiantes_inscritos, ca.ingreso_proyectado;

CREATE OR REPLACE VIEW finance.vista_movimientos_caja AS
SELECT
    ef.id,
    ef.tipo_evento,
    ef.monto,
    ef.descripcion,
    ef.fecha_evento,
    ef.saldo_resultante,
    p.nombres || ' ' || p.apellidos AS registrado_por_nombre
FROM audit.eventos_financieros ef
LEFT JOIN people.personas p ON ef.registrado_por = p.id
ORDER BY ef.fecha_evento, ef.id;

-- =============================================================================
-- 11. TRIGGERS
-- =============================================================================
CREATE OR REPLACE FUNCTION finance.fn_actualizar_cuenta_cobrar()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
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

CREATE TRIGGER trg_actualizar_saldo
AFTER INSERT OR UPDATE OR DELETE ON finance.transacciones_ingreso
FOR EACH ROW EXECUTE FUNCTION finance.fn_actualizar_cuenta_cobrar();

CREATE OR REPLACE FUNCTION academic.fn_actualizar_perfil_estudiante()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
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

CREATE TRIGGER trg_actualizar_perfil_estudiante
AFTER INSERT OR UPDATE ON academic.matriculas
FOR EACH ROW EXECUTE FUNCTION academic.fn_actualizar_perfil_estudiante();

CREATE OR REPLACE FUNCTION academic.fn_actualizar_resumen_curso()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
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

CREATE TRIGGER trg_actualizar_resumen_curso
AFTER INSERT OR UPDATE OR DELETE ON academic.matriculas
FOR EACH ROW EXECUTE FUNCTION academic.fn_actualizar_resumen_curso();

CREATE OR REPLACE FUNCTION finance.fn_registrar_movimiento_caja()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
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

CREATE TRIGGER trg_resumen_caja_ingreso
AFTER INSERT OR UPDATE OR DELETE ON finance.transacciones_ingreso
FOR EACH ROW EXECUTE FUNCTION finance.fn_registrar_movimiento_caja();

CREATE TRIGGER trg_resumen_caja_egreso
AFTER INSERT OR UPDATE OR DELETE ON finance.transacciones_egreso
FOR EACH ROW EXECUTE FUNCTION finance.fn_registrar_movimiento_caja();

CREATE OR REPLACE FUNCTION core.fn_set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_personas_updated_at
BEFORE UPDATE ON people.personas
FOR EACH ROW EXECUTE FUNCTION core.fn_set_updated_at();

-- =============================================================================
-- 12. ÍNDICES DE ALTO RENDIMIENTO
-- =============================================================================
CREATE INDEX idx_personas_cedula         ON people.personas(cedula) WHERE deleted_at IS NULL;
CREATE INDEX idx_personas_tipo           ON people.personas(tipo) WHERE deleted_at IS NULL;
CREATE INDEX idx_personas_nombres_trgm   ON people.personas USING GIN (nombres gin_trgm_ops);
CREATE INDEX idx_personas_apellidos_trgm ON people.personas USING GIN (apellidos gin_trgm_ops);

CREATE INDEX idx_clientes_externos_cedula   ON people.clientes_externos(cedula);
CREATE INDEX idx_clientes_externos_nombres  ON people.clientes_externos USING GIN (nombres gin_trgm_ops);
CREATE INDEX idx_clientes_externos_apellidos ON people.clientes_externos USING GIN (apellidos gin_trgm_ops);

CREATE INDEX idx_matriculas_estudiante   ON academic.matriculas(estudiante_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_matriculas_curso        ON academic.matriculas(curso_abierto_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_asistencias_clase       ON academic.asistencias(clase_id);
CREATE INDEX idx_clases_fecha            ON academic.clases(fecha_clase);
CREATE INDEX idx_cursos_estado           ON academic.cursos_abiertos(estado) WHERE deleted_at IS NULL;

CREATE INDEX idx_cpc_matricula           ON finance.cuentas_por_cobrar(matricula_id)           WHERE matricula_id IS NOT NULL;
CREATE INDEX idx_cpc_reserva_aula        ON finance.cuentas_por_cobrar(reserva_aula_id)        WHERE reserva_aula_id IS NOT NULL;
CREATE INDEX idx_cpc_reserva_podcast     ON finance.cuentas_por_cobrar(reserva_podcast_id)     WHERE reserva_podcast_id IS NOT NULL;
CREATE INDEX idx_cpc_streaming           ON finance.cuentas_por_cobrar(servicio_streaming_id)  WHERE servicio_streaming_id IS NOT NULL;
CREATE INDEX idx_cpc_produccion          ON finance.cuentas_por_cobrar(servicio_produccion_id) WHERE servicio_produccion_id IS NOT NULL;

CREATE INDEX idx_ingresos_fecha          ON finance.transacciones_ingreso(fecha_pago DESC);
CREATE INDEX idx_egresos_fecha           ON finance.transacciones_egreso(fecha_pago DESC);
CREATE INDEX idx_horas_instructor_pago   ON finance.horas_instructor(instructor_id, pagado);
CREATE INDEX idx_staff_asistencia_fecha  ON ops.registro_asistencia_staff(persona_id, fecha);
CREATE INDEX idx_audit_inicios_sesion_fecha ON audit.inicios_sesion(fecha_inicio DESC);
CREATE INDEX idx_audit_eventos_financieros_fecha ON audit.eventos_financieros(fecha_evento DESC);
CREATE INDEX idx_cursos_abiertos_resumen ON academic.cursos_abiertos(estudiantes_inscritos, ingreso_proyectado);

CREATE INDEX idx_equipos_estado     ON services.equipos(estado);
CREATE INDEX idx_equipos_nombre     ON services.equipos USING GIN (nombre gin_trgm_ops);
CREATE INDEX idx_alquiler_eq_equipo ON services.alquiler_equipos(equipo_id);
CREATE INDEX idx_alquiler_eq_estado ON services.alquiler_equipos(estado);
CREATE INDEX idx_alquiler_eq_fechas ON services.alquiler_equipos(fecha_entrega, fecha_devolucion_esperada);
CREATE INDEX idx_alquiler_eq_persona ON services.alquiler_equipos(persona_id) WHERE persona_id IS NOT NULL;
CREATE INDEX idx_alquiler_eq_cliente ON services.alquiler_equipos(cliente_externo_id) WHERE cliente_externo_id IS NOT NULL;
CREATE INDEX idx_cpc_alquiler_equipo ON finance.cuentas_por_cobrar(alquiler_equipo_id) WHERE alquiler_equipo_id IS NOT NULL;
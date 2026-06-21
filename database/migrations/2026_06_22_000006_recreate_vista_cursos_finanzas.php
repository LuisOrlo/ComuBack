<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS academic.vista_cursos_finanzas');

        DB::statement("
            CREATE VIEW academic.vista_cursos_finanzas AS
            SELECT
                ca.id,
                cc.nombre AS curso,
                ca.modalidad,
                ca.precio_base,
                ca.capacidad_maxima,
                ca.estudiantes_inscritos,
                ca.ingreso_proyectado,
                COALESCE(
                    (
                        SELECT SUM(lpm2.monto_ajustado)
                        FROM finance.lineas_pago_modulo lpm2
                        JOIN academic.matriculas m2 ON m2.id = lpm2.matricula_id
                        WHERE m2.curso_abierto_id = ca.id AND m2.deleted_at IS NULL
                    ),
                    COALESCE(SUM(m.precio_total_legacy) FILTER (WHERE m.deleted_at IS NULL), 0::numeric)
                ) AS ingreso_matriculado_real
            FROM academic.cursos_abiertos ca
            JOIN academic.catalogo_cursos cc ON cc.id = ca.catalogo_curso_id
            LEFT JOIN academic.matriculas m ON m.curso_abierto_id = ca.id
            GROUP BY ca.id, cc.nombre, ca.modalidad, ca.precio_base,
                     ca.capacidad_maxima, ca.estudiantes_inscritos, ca.ingreso_proyectado
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS academic.vista_cursos_finanzas');

        DB::statement("
            CREATE VIEW academic.vista_cursos_finanzas AS
            SELECT
                ca.id,
                cc.nombre AS curso,
                ca.modalidad,
                ca.precio_base,
                ca.capacidad_maxima,
                ca.estudiantes_inscritos,
                ca.ingreso_proyectado,
                COALESCE(SUM(m.precio_total_legacy) FILTER (WHERE m.deleted_at IS NULL), 0::numeric) AS ingreso_matriculado_real
            FROM academic.cursos_abiertos ca
            JOIN academic.catalogo_cursos cc ON cc.id = ca.catalogo_curso_id
            LEFT JOIN academic.matriculas m ON m.curso_abierto_id = ca.id
            GROUP BY ca.id, cc.nombre, ca.modalidad, ca.precio_base,
                     ca.capacidad_maxima, ca.estudiantes_inscritos, ca.ingreso_proyectado
        ");
    }
};

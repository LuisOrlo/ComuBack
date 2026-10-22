<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Activar los triggers existentes en PostgreSQL sobre academic.matriculas
        DB::statement('ALTER TABLE academic.matriculas ENABLE TRIGGER trg_actualizar_resumen_curso;');
        DB::statement('ALTER TABLE academic.matriculas ENABLE TRIGGER trg_actualizar_perfil_estudiante;');

        // 2. Sincronizar todos los cursos abiertos con sus matrículas reales activas
        DB::statement("
            UPDATE academic.cursos_abiertos ca
            SET estudiantes_inscritos = COALESCE((
                SELECT COUNT(*)
                FROM academic.matriculas m
                WHERE m.curso_abierto_id = ca.id
                  AND m.deleted_at IS NULL
            ), 0),
            ingreso_proyectado = ca.precio_base * COALESCE((
                SELECT COUNT(*)
                FROM academic.matriculas m
                WHERE m.curso_abierto_id = ca.id
                  AND m.deleted_at IS NULL
            ), 0);
        ");

        // 3. Sincronizar el total de cursos en los perfiles de estudiantes
        DB::statement("
            UPDATE people.perfil_estudiante pe
            SET total_cursos = COALESCE((
                SELECT COUNT(*)
                FROM academic.matriculas m
                WHERE m.estudiante_id = pe.persona_id
                  AND m.deleted_at IS NULL
            ), 0);
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE academic.matriculas DISABLE TRIGGER trg_actualizar_resumen_curso;');
        DB::statement('ALTER TABLE academic.matriculas DISABLE TRIGGER trg_actualizar_perfil_estudiante;');
    }
};

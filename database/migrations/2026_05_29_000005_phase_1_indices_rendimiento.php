<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // ============================================================================
        // FASE 1.5: Crear índices de rendimiento críticos
        // ============================================================================

        // Índices en horarios_dias
        DB::connection('pgsql')->statement('CREATE INDEX idx_horarios_dias_horario_id ON academic.horarios_dias(horario_id)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_horarios_dias_dia_semana ON academic.horarios_dias(dia_semana)');

        // Índices en matriculas
        DB::connection('pgsql')->statement('CREATE INDEX idx_matriculas_estudiante_id ON academic.matriculas(estudiante_id)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_matriculas_curso_abierto_id ON academic.matriculas(curso_abierto_id)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_matriculas_estado ON academic.matriculas(estado)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_matriculas_deleted_at ON academic.matriculas(deleted_at)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_matriculas_composite ON academic.matriculas(curso_abierto_id, estado, deleted_at)');

        // Índices en notas
        DB::connection('pgsql')->statement('CREATE INDEX idx_notas_matricula_id ON academic.notas(matricula_id)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_notas_modulo_id ON academic.notas(modulo_id)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_notas_composite ON academic.notas(matricula_id, modulo_id)');

        // Índices en cambios_horario
        DB::connection('pgsql')->statement('CREATE INDEX idx_cambios_horario_matricula_origen ON academic.cambios_horario(matricula_origen_id)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_cambios_horario_estado ON academic.cambios_horario(estado)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_cambios_horario_fecha ON academic.cambios_horario(created_at)');

        // Índices en cursos_abiertos
        DB::connection('pgsql')->statement('CREATE INDEX idx_cursos_abiertos_catalogo_id ON academic.cursos_abiertos(catalogo_curso_id)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_cursos_abiertos_estado ON academic.cursos_abiertos(es_activo)');

        // Índices en catalogo_cursos
        DB::connection('pgsql')->statement('CREATE INDEX idx_catalogo_cursos_programa_id ON academic.catalogo_cursos(programa_id)');
        DB::connection('pgsql')->statement('CREATE INDEX idx_catalogo_cursos_codigo ON academic.catalogo_cursos(codigo)');
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // Eliminar índices
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_horarios_dias_horario_id');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_horarios_dias_dia_semana');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_matriculas_estudiante_id');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_matriculas_curso_abierto_id');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_matriculas_estado');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_matriculas_deleted_at');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_matriculas_composite');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_notas_matricula_id');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_notas_modulo_id');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_notas_composite');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_cambios_horario_matricula_origen');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_cambios_horario_estado');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_cambios_horario_fecha');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_cursos_abiertos_catalogo_id');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_cursos_abiertos_estado');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_catalogo_cursos_programa_id');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS academic.idx_catalogo_cursos_codigo');
    }
};

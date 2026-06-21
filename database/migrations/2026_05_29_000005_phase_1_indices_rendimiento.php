<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // ============================================================================
        // FASE 1.5: Crear índices de rendimiento críticos
        // Cada índice se crea de forma individual y defensiva: si falla uno,
        // los demás continúan.
        // ============================================================================

        $this->safeCreateIndex('idx_horarios_dias_horario_id', 'academic.horarios_dias', ['horario_id']);
        $this->safeCreateIndex('idx_horarios_dias_dia_semana', 'academic.horarios_dias', ['dia_semana']);

        $this->safeCreateIndex('idx_matriculas_estudiante_id', 'academic.matriculas', ['estudiante_id']);
        $this->safeCreateIndex('idx_matriculas_curso_abierto_id', 'academic.matriculas', ['curso_abierto_id']);
        $this->safeCreateIndex('idx_matriculas_estado', 'academic.matriculas', ['estado']);
        $this->safeCreateIndex('idx_matriculas_deleted_at', 'academic.matriculas', ['deleted_at']);
        $this->safeCreateIndex('idx_matriculas_composite', 'academic.matriculas', ['curso_abierto_id', 'estado', 'deleted_at']);

        $this->safeCreateIndex('idx_notas_matricula_id', 'academic.notas', ['matricula_id']);
        $this->safeCreateIndex('idx_notas_modulo_id', 'academic.notas', ['modulo_id']);
        $this->safeCreateIndex('idx_notas_composite', 'academic.notas', ['matricula_id', 'modulo_id']);

        $this->safeCreateIndex('idx_cambios_horario_matricula_origen', 'academic.cambios_horario', ['matricula_origen_id']);
        $this->safeCreateIndex('idx_cambios_horario_estado', 'academic.cambios_horario', ['estado']);
        $this->safeCreateIndex('idx_cambios_horario_fecha', 'academic.cambios_horario', ['created_at']);

        $this->safeCreateIndex('idx_cursos_abiertos_catalogo_id', 'academic.cursos_abiertos', ['catalogo_curso_id']);
        $this->safeCreateIndex('idx_cursos_abiertos_estado', 'academic.cursos_abiertos', ['es_activo']);

        $this->safeCreateIndex('idx_catalogo_cursos_programa_id', 'academic.catalogo_cursos', ['programa_id']);
        $this->safeCreateIndex('idx_catalogo_cursos_codigo', 'academic.catalogo_cursos', ['codigo']);
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        $indexes = [
            'idx_horarios_dias_horario_id',
            'idx_horarios_dias_dia_semana',
            'idx_matriculas_estudiante_id',
            'idx_matriculas_curso_abierto_id',
            'idx_matriculas_estado',
            'idx_matriculas_deleted_at',
            'idx_matriculas_composite',
            'idx_notas_matricula_id',
            'idx_notas_modulo_id',
            'idx_notas_composite',
            'idx_cambios_horario_matricula_origen',
            'idx_cambios_horario_estado',
            'idx_cambios_horario_fecha',
            'idx_cursos_abiertos_catalogo_id',
            'idx_cursos_abiertos_estado',
            'idx_catalogo_cursos_programa_id',
            'idx_catalogo_cursos_codigo',
        ];

        foreach ($indexes as $index) {
            try {
                DB::connection('pgsql')->statement("DROP INDEX IF EXISTS academic.{$index}");
            } catch (\Exception $e) {
                // Ignorar si no existe
            }
        }
    }

    /**
     * Crea un índice de forma segura: primero verifica que la tabla y las columnas existan,
     * y no lanza excepción si falla.
     */
    private function safeCreateIndex(string $indexName, string $table, array $columns): void
    {
        try {
            // Verificar que la tabla existe
            if (!Schema::connection('pgsql')->hasTable($table)) {
                return;
            }

            // Verificar que las columnas existen
            $existingColumns = Schema::connection('pgsql')->getColumnListing($table);
            foreach ($columns as $col) {
                if (!in_array($col, $existingColumns)) {
                    return;
                }
            }

            // Crear índice
            $colList = implode(', ', $columns);
            DB::connection('pgsql')->statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table}({$colList})"
            );
        } catch (\Exception $e) {
            // Si el índice ya existe o la tabla/columna no está disponible, continuar
        }
    }
};

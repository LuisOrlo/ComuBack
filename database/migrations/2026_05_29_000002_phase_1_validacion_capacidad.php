<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Solo ejecutar en PostgreSQL (las triggers no son soportadas en SQLite)
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // ============================================================================
        // FASE 1.2: Agregar validación de capacidad máxima
        // ============================================================================

        // Crear función PL/pgSQL para validar capacidad
        DB::connection('pgsql')->statement('
            CREATE OR REPLACE FUNCTION academic.fn_validar_capacidad_curso()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
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
                  AND estado IN (\'activo\', \'completado\')
                  AND deleted_at IS NULL;
                
                -- Validar que no exceda capacidad
                IF v_inscritos >= v_capacidad THEN
                    RAISE EXCEPTION \'Capacidad máxima (%) del curso alcanzada. Inscritos actuales: %\', 
                        v_capacidad, v_inscritos;
                END IF;
                
                RETURN NEW;
            END;
            $$;
        ');

        // Crear trigger ANTES de insertar matrícula
        DB::connection('pgsql')->statement('
            DROP TRIGGER IF EXISTS trg_validar_capacidad ON academic.matriculas;
            CREATE TRIGGER trg_validar_capacidad
            BEFORE INSERT ON academic.matriculas
            FOR EACH ROW
            EXECUTE FUNCTION academic.fn_validar_capacidad_curso();
        ');
    }

    public function down(): void
    {
        // Solo ejecutar en PostgreSQL
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // Eliminar trigger y función
        DB::connection('pgsql')->statement('
            DROP TRIGGER IF EXISTS trg_validar_capacidad ON academic.matriculas;
            DROP FUNCTION IF EXISTS academic.fn_validar_capacidad_curso();
        ');
    }
};

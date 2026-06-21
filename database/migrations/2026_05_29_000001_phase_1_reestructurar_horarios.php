<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Solo ejecutar en PostgreSQL
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // ============================================================================
        // FASE 1.1: Reestructurar horarios.dia_semana → horarios_dias
        // ============================================================================

        // Paso 1: Crear tabla nueva horarios_dias
        if (Schema::connection('pgsql')->hasTable('academic.horarios_dias')) {
            return;
        }
        Schema::connection('pgsql')->create('academic.horarios_dias', function (Blueprint $table) {
            $table->id();
            $table->uuid('horario_id')->references('id')->on('academic.horarios')->onDelete('cascade');
            $table->smallInteger('dia_semana')->comment('1=Lunes, 2=Martes, ..., 7=Domingo');
            $table->unique(['horario_id', 'dia_semana']);
            $table->index('horario_id');
            $table->index('dia_semana');
        });

        // Paso 2: Migrar datos existentes desde horarios.dia_semana si existen
        if (Schema::connection('pgsql')->hasColumn('academic.horarios', 'dia_semana')) {
            DB::connection('pgsql')->statement('
                INSERT INTO academic.horarios_dias (horario_id, dia_semana)
                SELECT id, UNNEST(dia_semana)
                FROM academic.horarios
                WHERE dia_semana IS NOT NULL
            ');
        }

        // Paso 3: Crear vista para compatibilidad con código existente
        DB::connection('pgsql')->statement('
            CREATE OR REPLACE VIEW academic.v_horarios_con_dias AS
            SELECT 
                h.id,
                h.nombre_referencial,
                h.hora_inicio,
                h.hora_fin,
                h.es_activo,
                COALESCE(ARRAY_AGG(hd.dia_semana ORDER BY hd.dia_semana), ARRAY[]::SMALLINT[]) AS dia_semana
            FROM academic.horarios h
            LEFT JOIN academic.horarios_dias hd ON h.id = hd.horario_id
            GROUP BY h.id, h.nombre_referencial, h.hora_inicio, h.hora_fin, h.es_activo
        ');
    }

    public function down(): void
    {
        // Solo ejecutar en PostgreSQL
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // Eliminar vista
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS academic.v_horarios_con_dias');
        
        // Eliminar tabla
        Schema::connection('pgsql')->dropIfExists('academic.horarios_dias');
    }
};

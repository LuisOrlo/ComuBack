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
        // FASE 1.4: Auditoría en cambios_horario
        // ============================================================================

        // Crear tabla de auditoría si no existe
        if (!Schema::connection('pgsql')->hasTable('audit.cambios_horario_auditoria')) {
            Schema::connection('pgsql')->create('audit.cambios_horario_auditoria', function ($table) {
                $table->bigIncrements('id');
                $table->uuid('cambio_horario_id')->nullable();
                $table->uuid('matricula_origen_id')->nullable();
                $table->uuid('curso_abierto_antiguo_id')->nullable();
                $table->uuid('curso_abierto_nuevo_id')->nullable();
                $table->string('motivo', 255)->nullable();
                $table->enum('estado', ['pendiente', 'aprobado', 'rechazado', 'completado'])->default('pendiente');
                $table->string('accion', 50); // INSERT, UPDATE, DELETE
                $table->string('usuario_id', 255)->nullable();
                $table->json('datos_anteriores')->nullable();
                $table->json('datos_nuevos')->nullable();
                $table->timestamp('fecha_cambio')->useCurrent();
                $table->index('cambio_horario_id');
                $table->index('matricula_origen_id');
                $table->index('fecha_cambio');
            });
        }

        // Crear función de auditoría
        DB::connection('pgsql')->statement('
            CREATE OR REPLACE FUNCTION audit.fn_auditar_cambios_horario()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
                v_datos_anteriores JSON;
                v_datos_nuevos JSON;
                v_accion VARCHAR;
            BEGIN
                IF TG_OP = \'INSERT\' THEN
                    v_accion := \'INSERT\';
                    v_datos_anteriores := NULL;
                    v_datos_nuevos := ROW_TO_JSON(NEW);
                ELSIF TG_OP = \'UPDATE\' THEN
                    v_accion := \'UPDATE\';
                    v_datos_anteriores := ROW_TO_JSON(OLD);
                    v_datos_nuevos := ROW_TO_JSON(NEW);
                ELSIF TG_OP = \'DELETE\' THEN
                    v_accion := \'DELETE\';
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
        ');

        // Crear trigger para auditoría
        DB::connection('pgsql')->statement('
            DROP TRIGGER IF EXISTS trg_auditar_cambios_horario ON academic.cambios_horario
        ');
        DB::connection('pgsql')->statement('
            CREATE TRIGGER trg_auditar_cambios_horario
            AFTER INSERT OR UPDATE OR DELETE ON academic.cambios_horario
            FOR EACH ROW
            EXECUTE FUNCTION audit.fn_auditar_cambios_horario()
        ');
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // Eliminar trigger y función
        DB::connection('pgsql')->statement('
            DROP TRIGGER IF EXISTS trg_auditar_cambios_horario ON academic.cambios_horario
        ');
        DB::connection('pgsql')->statement('
            DROP FUNCTION IF EXISTS audit.fn_auditar_cambios_horario()
        ');
        
        // Opcional: eliminar tabla de auditoría
        Schema::connection('pgsql')->dropIfExists('audit.cambios_horario_auditoria');
    }
};

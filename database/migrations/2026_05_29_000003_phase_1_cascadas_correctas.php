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
        // FASE 1.3: Agregar cascadas correctas en relaciones
        // ============================================================================

        // Modificar cambios_horario: CASCADE en matricula, RESTRICT en curso
        DB::connection('pgsql')->statement('
            ALTER TABLE academic.cambios_horario 
            DROP CONSTRAINT IF EXISTS cambios_horario_matricula_origen_id_fkey;
        ');

        DB::connection('pgsql')->statement('
            ALTER TABLE academic.cambios_horario 
            ADD CONSTRAINT cambios_horario_matricula_origen_id_fkey 
                FOREIGN KEY (matricula_origen_id) 
                REFERENCES academic.matriculas(id) 
                ON DELETE CASCADE;
        ');

        DB::connection('pgsql')->statement('
            ALTER TABLE academic.cambios_horario 
            DROP CONSTRAINT IF EXISTS cambios_horario_curso_abierto_nuevo_id_fkey;
        ');

        DB::connection('pgsql')->statement('
            ALTER TABLE academic.cambios_horario 
            ADD CONSTRAINT cambios_horario_curso_abierto_nuevo_id_fkey 
                FOREIGN KEY (curso_abierto_nuevo_id) 
                REFERENCES academic.cursos_abiertos(id) 
                ON DELETE RESTRICT;
        ');

        // Modificar traslados_modulo: CASCADE en matricula
        DB::connection('pgsql')->statement('
            ALTER TABLE academic.traslados_modulo 
            DROP CONSTRAINT IF EXISTS traslados_modulo_matricula_origen_id_fkey;
        ');

        DB::connection('pgsql')->statement('
            ALTER TABLE academic.traslados_modulo 
            ADD CONSTRAINT traslados_modulo_matricula_origen_id_fkey 
                FOREIGN KEY (matricula_origen_id) 
                REFERENCES academic.matriculas(id) 
                ON DELETE CASCADE;
        ');
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        // Revertir a configuración anterior (sin CASCADE específicamente)
        DB::connection('pgsql')->statement('
            ALTER TABLE academic.cambios_horario 
            DROP CONSTRAINT IF EXISTS cambios_horario_matricula_origen_id_fkey;
        ');

        DB::connection('pgsql')->statement('
            ALTER TABLE academic.traslados_modulo 
            DROP CONSTRAINT IF EXISTS traslados_modulo_matricula_origen_id_fkey;
        ');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_matriculas_solicitud_id ON academic.matriculas(solicitud_inscripcion_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_matriculas_estudiante_estado ON academic.matriculas(estudiante_id, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_asistencias_matricula_id ON academic.asistencias(matricula_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_asistencias_clase_id ON academic.asistencias(clase_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_clases_modulo_id ON academic.clases(modulo_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_certificados_curso_abierto_id ON academic.certificados(curso_abierto_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_certificados_estudiante_id ON academic.certificados(estudiante_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_personas_tipo ON people.personas(tipo)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS academic.idx_matriculas_solicitud_id');
        DB::statement('DROP INDEX IF EXISTS academic.idx_matriculas_estudiante_estado');
        DB::statement('DROP INDEX IF EXISTS academic.idx_asistencias_matricula_id');
        DB::statement('DROP INDEX IF EXISTS academic.idx_asistencias_clase_id');
        DB::statement('DROP INDEX IF EXISTS academic.idx_clases_modulo_id');
        DB::statement('DROP INDEX IF EXISTS academic.idx_certificados_curso_abierto_id');
        DB::statement('DROP INDEX IF EXISTS academic.idx_certificados_estudiante_id');
        DB::statement('DROP INDEX IF EXISTS people.idx_personas_tipo');
    }
};

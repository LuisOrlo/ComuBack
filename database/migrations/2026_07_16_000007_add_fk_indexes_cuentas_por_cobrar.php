<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_estado ON finance.cuentas_por_cobrar (estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_solicitud_inscripcion_id ON finance.cuentas_por_cobrar (solicitud_inscripcion_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_inscripcion_taller_id ON finance.cuentas_por_cobrar (inscripcion_taller_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_alquiler_equipo_id ON finance.cuentas_por_cobrar (alquiler_equipo_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_edicion_video_id ON finance.cuentas_por_cobrar (edicion_video_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_clase_extra_id ON finance.cuentas_por_cobrar (clase_extra_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_asesoria_id ON finance.cuentas_por_cobrar (asesoria_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_estado');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_solicitud_inscripcion_id');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_inscripcion_taller_id');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_alquiler_equipo_id');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_edicion_video_id');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_clase_extra_id');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_asesoria_id');
    }
};

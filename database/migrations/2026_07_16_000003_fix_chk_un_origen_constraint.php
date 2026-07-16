<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE finance.cuentas_por_cobrar DROP CONSTRAINT IF EXISTS chk_un_origen');

        DB::statement("
            ALTER TABLE finance.cuentas_por_cobrar
            ADD CONSTRAINT chk_un_origen
            CHECK (
                num_nonnulls(
                    matricula_id,
                    inscripcion_taller_id,
                    reserva_aula_id,
                    reserva_podcast_id,
                    reserva_radio_id,
                    servicio_streaming_id,
                    servicio_produccion_id,
                    edicion_video_id,
                    alquiler_equipo_id,
                    clase_extra_id,
                    asesoria_id,
                    solicitud_inscripcion_id
                ) = 1
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE finance.cuentas_por_cobrar DROP CONSTRAINT IF EXISTS chk_un_origen');

        DB::statement("
            ALTER TABLE finance.cuentas_por_cobrar
            ADD CONSTRAINT chk_un_origen
            CHECK (
                num_nonnulls(
                    matricula_id,
                    inscripcion_taller_id,
                    reserva_aula_id,
                    reserva_podcast_id,
                    servicio_streaming_id,
                    servicio_produccion_id,
                    edicion_video_id,
                    alquiler_equipo_id,
                    clase_extra_id,
                    asesoria_id
                ) = 1
            )
        ");
    }
};

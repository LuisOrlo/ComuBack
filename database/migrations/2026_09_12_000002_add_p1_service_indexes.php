<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reservas_aulas_disponibilidad ON services.reservas_aulas (aula_id, fecha_reserva, hora_inicio, hora_fin, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_alquileres_equipo_estado ON services.alquiler_equipos (equipo_id, estado, fecha_devolucion_esperada)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_estado_created ON finance.cuentas_por_cobrar (estado, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS services.idx_reservas_aulas_disponibilidad');
        DB::statement('DROP INDEX IF EXISTS services.idx_alquileres_equipo_estado');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_estado_created');
    }
};

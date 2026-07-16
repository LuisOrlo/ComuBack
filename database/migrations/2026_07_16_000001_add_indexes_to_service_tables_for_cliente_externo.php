<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reservas_radio_cliente_externo ON services.reservas_radio USING btree (cliente_externo_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reservas_aulas_cliente_externo ON services.reservas_aulas USING btree (cliente_externo_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reservas_podcast_cliente_externo ON services.reservas_podcast USING btree (cliente_externo_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_alquiler_equipos_cliente_externo ON services.alquiler_equipos USING btree (cliente_externo_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cpc_alquiler_equipo ON finance.cuentas_por_cobrar USING btree (alquiler_equipo_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS services.idx_reservas_radio_cliente_externo');
        DB::statement('DROP INDEX IF EXISTS services.idx_reservas_aulas_cliente_externo');
        DB::statement('DROP INDEX IF EXISTS services.idx_reservas_podcast_cliente_externo');
        DB::statement('DROP INDEX IF EXISTS services.idx_alquiler_equipos_cliente_externo');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cpc_alquiler_equipo');
    }
};

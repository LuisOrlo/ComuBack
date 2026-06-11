<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql')->statement("
            ALTER TABLE services.alquiler_equipos
            DROP CONSTRAINT IF EXISTS alquiler_equipos_estado_check
        ");

        DB::connection('pgsql')->statement("
            ALTER TABLE services.alquiler_equipos
            ADD CONSTRAINT alquiler_equipos_estado_check
            CHECK (estado IN ('activo','devuelto','vencido','pendiente','entregado'))
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement("
            ALTER TABLE services.alquiler_equipos
            DROP CONSTRAINT IF EXISTS alquiler_equipos_estado_check
        ");

        DB::connection('pgsql')->statement("
            ALTER TABLE services.alquiler_equipos
            ADD CONSTRAINT alquiler_equipos_estado_check
            CHECK (estado IN ('activo','devuelto','vencido'))
        ");
    }
};

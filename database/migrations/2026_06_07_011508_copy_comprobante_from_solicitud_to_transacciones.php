<?php

use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql')->statement("
            UPDATE finance.transacciones_ingreso ti
            SET comprobante_url = s.archivo_comprobante_url
            FROM finance.cuentas_por_cobrar c
            JOIN academic.solicitudes_inscripcion s ON s.id = c.solicitud_inscripcion_id
            WHERE ti.cuenta_cobrar_id = c.id
              AND s.archivo_comprobante_url IS NOT NULL
              AND (ti.comprobante_url IS NULL OR ti.comprobante_url = '')
        ");
    }

    public function down(): void
    {
        // no reversible
    }
};

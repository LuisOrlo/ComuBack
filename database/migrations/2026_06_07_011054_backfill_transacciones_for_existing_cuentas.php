<?php

use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql')->statement("DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 't_metodo_pago') THEN
                CREATE TYPE finance.t_metodo_pago AS ENUM ('efectivo', 'transferencia', 'deposito', 'tarjeta', 'otro');
            END IF;
        END $$;");

        CuentaPorCobrar::where('monto_abonado', '>', 0)
            ->whereDoesntHave('transacciones')
            ->chunk(100, function ($cuentas) {
                foreach ($cuentas as $cuenta) {
                    TransaccionIngreso::create([
                        'cuenta_cobrar_id'    => $cuenta->id,
                        'monto'               => $cuenta->monto_abonado,
                        'metodo_pago'         => 'efectivo',
                        'fecha_pago'          => $cuenta->created_at ?? now(),
                        'estado_verificacion' => 'aprobado',
                        'observaciones'       => 'Migración automática — abono preexistente',
                    ]);
                }
            });
    }

    public function down(): void
    {
        TransaccionIngreso::where('observaciones', 'Migración automática — abono preexistente')->delete();
    }
};

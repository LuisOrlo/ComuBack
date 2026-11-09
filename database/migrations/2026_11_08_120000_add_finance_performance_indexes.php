<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = config('database.default');

        // 1. Índices en finance.transacciones_ingreso para acelerar cruces con cuentas y módulos
        if (Schema::connection($connection)->hasTable('finance.transacciones_ingreso')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_transacciones_ingreso_cuenta_cobrar ON finance.transacciones_ingreso (cuenta_cobrar_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_transacciones_ingreso_linea_pago ON finance.transacciones_ingreso (linea_pago_modulo_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_transacciones_ingreso_metodo ON finance.transacciones_ingreso (metodo_pago)');
        }

        // 2. Índices parciales / de origen en finance.cuentas_por_cobrar
        if (Schema::connection($connection)->hasTable('finance.cuentas_por_cobrar')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_matricula ON finance.cuentas_por_cobrar (matricula_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_taller ON finance.cuentas_por_cobrar (inscripcion_taller_id)');
            
            // Índice parcial para consultas rápidas de Academia
            DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_origen_academia ON finance.cuentas_por_cobrar (id) WHERE matricula_id IS NOT NULL OR inscripcion_taller_id IS NOT NULL');
            
            // Índice parcial para consultas rápidas de Servicios
            DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_origen_servicios ON finance.cuentas_por_cobrar (id) WHERE reserva_aula_id IS NOT NULL OR reserva_podcast_id IS NOT NULL OR reserva_radio_id IS NOT NULL OR reserva_set_id IS NOT NULL OR alquiler_equipo_id IS NOT NULL OR servicio_streaming_id IS NOT NULL OR servicio_produccion_id IS NOT NULL OR edicion_video_id IS NOT NULL OR clase_extra_id IS NOT NULL OR asesoria_id IS NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS finance.idx_transacciones_ingreso_cuenta_cobrar');
        DB::statement('DROP INDEX IF EXISTS finance.idx_transacciones_ingreso_linea_pago');
        DB::statement('DROP INDEX IF EXISTS finance.idx_transacciones_ingreso_metodo');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_matricula');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_taller');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_origen_academia');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_origen_servicios');
    }
};

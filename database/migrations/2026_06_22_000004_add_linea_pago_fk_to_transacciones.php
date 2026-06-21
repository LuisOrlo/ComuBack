<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance.transacciones_ingreso', function (Blueprint $table) {
            $table->foreignUuid('linea_pago_modulo_id')
                ->nullable()
                ->after('cuenta_cobrar_id')
                ->constrained('finance.lineas_pago_modulo');
        });
    }

    public function down(): void
    {
        Schema::table('finance.transacciones_ingreso', function (Blueprint $table) {
            $table->dropForeign(['linea_pago_modulo_id']);
            $table->dropColumn('linea_pago_modulo_id');
        });
    }
};

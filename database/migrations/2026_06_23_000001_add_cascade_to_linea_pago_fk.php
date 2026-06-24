<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance.transacciones_ingreso', function (Blueprint $table) {
            $table->dropForeign(['linea_pago_modulo_id']);
            $table->foreign('linea_pago_modulo_id')
                ->references('id')
                ->on('finance.lineas_pago_modulo')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('finance.transacciones_ingreso', function (Blueprint $table) {
            $table->dropForeign(['linea_pago_modulo_id']);
            $table->foreign('linea_pago_modulo_id')
                ->references('id')
                ->on('finance.lineas_pago_modulo');
        });
    }
};

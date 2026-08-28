<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = [
            'services.reservas_aulas',
            'services.reservas_podcast',
            'services.reservas_radio',
            'services.alquiler_equipos',
            'services.trabajos_edicion',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('precio_original', 10, 2)->nullable();
                $table->decimal('monto_descuento', 10, 2)->default(0);
                $table->string('motivo_descuento')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'services.reservas_aulas',
            'services.reservas_podcast',
            'services.reservas_radio',
            'services.alquiler_equipos',
            'services.trabajos_edicion',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['precio_original', 'monto_descuento', 'motivo_descuento']);
            });
        }
    }
};

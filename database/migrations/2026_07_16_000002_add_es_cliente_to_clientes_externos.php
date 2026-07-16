<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people.clientes_externos', function (Blueprint $table) {
            $table->boolean('es_cliente')->default(false)->after('fecha_nacimiento');
        });

        DB::statement('
            UPDATE people.clientes_externos
            SET es_cliente = true
            WHERE EXISTS (
                SELECT 1 FROM services.reservas_radio r WHERE r.cliente_externo_id = clientes_externos.id
            )
            OR EXISTS (
                SELECT 1 FROM services.reservas_aulas a WHERE a.cliente_externo_id = clientes_externos.id
            )
            OR EXISTS (
                SELECT 1 FROM services.reservas_podcast p WHERE p.cliente_externo_id = clientes_externos.id
            )
            OR EXISTS (
                SELECT 1 FROM services.alquiler_equipos e WHERE e.cliente_externo_id = clientes_externos.id
            )
        ');
    }

    public function down(): void
    {
        Schema::table('people.clientes_externos', function (Blueprint $table) {
            $table->dropColumn('es_cliente');
        });
    }
};

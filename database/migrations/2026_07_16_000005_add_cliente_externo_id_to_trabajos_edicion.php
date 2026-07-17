<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('services.trabajos_edicion', function (Blueprint $table) {
            $table->foreignUuid('cliente_externo_id')
                ->nullable()
                ->constrained('people.clientes_externos')
                ->nullOnDelete();

            $table->index('cliente_externo_id', 'services_trabajos_edicion_cliente_externo_id_index');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('services.trabajos_edicion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_externo_id');
        });
    }
};

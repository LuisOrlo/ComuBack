<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Crear tabla clientes_externos en schema people
        if (Schema::connection('pgsql')->hasTable('people.clientes_externos')) {
            return;
        }
        Schema::connection('pgsql')->create('people.clientes_externos', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            
            // Datos personales
            $table->string('nombres', 100);
            $table->string('apellidos', 100)->nullable();
            $table->string('cedula', 20)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('celular', 20)->nullable();
            
            // Ubicación
            $table->unsignedBigInteger('ciudad_id')->nullable();
            
            // Información adicional
            $table->text('observaciones')->nullable();
            
            // Timestamps (sin soft deletes como en DB.sql original)
            $table->timestampsTz();
            
            // Índices para búsqueda frecuente
            $table->index('correo');
            $table->index('cedula');
            $table->index('ciudad_id');
            
            // Foreign Keys
            $table->foreign('ciudad_id')
                ->references('id')
                ->on('core.ciudades')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('people.clientes_externos');
    }
};

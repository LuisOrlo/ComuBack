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
        Schema::create('documentos_archivos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre', 255)->index();
            $table->text('descripcion')->nullable();
            $table->string('archivo_path', 500);
            $table->string('archivo_nombre_original', 255);
            $table->boolean('es_publico')->default(true)->index();
            $table->timestamps();

            // Índice compuesto para consultas instantáneas en el portal público
            $table->index(['es_publico', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentos_archivos');
    }
};

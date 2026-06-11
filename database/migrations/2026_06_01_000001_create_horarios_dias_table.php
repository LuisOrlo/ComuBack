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
        // Solo crear si no existe
        if (!Schema::connection(config('database.default'))->hasTable('academic.horarios_dias')) {
            Schema::connection(config('database.default'))->create('academic.horarios_dias', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('horario_id');
                $table->foreign('horario_id')->references('id')->on('academic.horarios')->onDelete('cascade');
                $table->integer('dia_semana'); // 1-7 (Lunes-Domingo)

                // Índices
                $table->index('horario_id');
                $table->index('dia_semana');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->dropIfExists('academic.horarios_dias');
    }
};


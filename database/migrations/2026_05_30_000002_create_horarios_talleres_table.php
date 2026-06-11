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
        Schema::connection(config('database.default'))->create('academic.horarios_talleres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('taller_id');
            $table->foreign('taller_id')->references('id')->on('academic.talleres')->onDelete('cascade');
            $table->integer('dia_semana'); // 1-7 (Lunes-Domingo)
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('aula')->nullable();
            $table->integer('capacidad');
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('taller_id');
            $table->index('dia_semana');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->dropIfExists('academic.horarios_talleres');
    }
};

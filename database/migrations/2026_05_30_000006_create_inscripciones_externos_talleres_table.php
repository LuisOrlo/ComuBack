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
        if (Schema::hasTable('academic.inscripciones_externos_talleres')) {
            return;
        }
        Schema::connection(config('database.default'))->create('academic.inscripciones_externos_talleres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('taller_id');
            $table->foreign('taller_id')->references('id')->on('academic.talleres')->onDelete('cascade');
            $table->uuid('participante_externo_id');
            $table->foreign('participante_externo_id')->references('id')->on('academic.participantes_externos')->onDelete('cascade');
            $table->date('fecha_inscripcion');
            $table->enum('estado', ['inscrito', 'completado', 'retirado'])->default('inscrito');
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('taller_id');
            $table->index('participante_externo_id');
            $table->unique(['taller_id', 'participante_externo_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->dropIfExists('academic.inscripciones_externos_talleres');
    }
};

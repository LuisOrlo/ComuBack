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
        if (Schema::connection('pgsql')->hasTable('academic.participantes_cursos_personalizados')) {
            return;
        }
        Schema::connection(config('database.default'))->create('academic.participantes_cursos_personalizados', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('curso_personalizado_id');
            $table->foreign('curso_personalizado_id')->references('id')->on('academic.cursos_abiertos')->onDelete('cascade');
            $table->uuid('participante_externo_id');
            $table->foreign('participante_externo_id')->references('id')->on('academic.participantes_externos')->onDelete('cascade');
            $table->date('fecha_inscripcion');
            $table->enum('estado', ['inscrito', 'completado', 'retirado'])->default('inscrito');
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('curso_personalizado_id');
            $table->index('participante_externo_id');
            $table->unique(['curso_personalizado_id', 'participante_externo_id'], 'pcp_curso_part_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->dropIfExists('academic.participantes_cursos_personalizados');
    }
};

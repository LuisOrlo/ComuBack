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
        Schema::connection(config('database.default'))->create('academic.participantes_externos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre');
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->string('institucion')->nullable();
            $table->enum('tipo', ['persona_externa', 'profesional', 'estudiante_externo'])->default('persona_externa');
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('email');
            $table->index('tipo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->dropIfExists('academic.participantes_externos');
    }
};

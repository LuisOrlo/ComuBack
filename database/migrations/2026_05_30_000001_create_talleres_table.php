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
        if (Schema::connection(config('database.default'))->hasTable('academic.talleres')) {
            return;
        }
        Schema::connection(config('database.default'))->create('academic.talleres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->enum('categoria', ['taller', 'seminario', 'workshop', 'capacitacion']);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->integer('capacidad');
            $table->uuid('profesor_id')->nullable();
            $table->foreign('profesor_id')->references('id')->on('people.personas');
            $table->enum('estado', ['planificado', 'activo', 'completado', 'cancelado'])->default('planificado');
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('estado');
            $table->index('fecha_inicio');
            $table->index('profesor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->dropIfExists('academic.talleres');
    }
};

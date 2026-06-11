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
        Schema::connection(config('database.default'))->create('academic.asistencias_talleres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('taller_id');
            $table->foreign('taller_id')->references('id')->on('academic.talleres')->onDelete('cascade');
            $table->date('fecha_sesion');
            $table->integer('asistentes')->default(0);
            $table->integer('capacidad_registrada')->default(0);
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('taller_id');
            $table->index('fecha_sesion');
            $table->unique(['taller_id', 'fecha_sesion']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->dropIfExists('academic.asistencias_talleres');
    }
};

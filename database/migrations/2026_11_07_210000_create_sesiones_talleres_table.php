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
        if (Schema::hasTable('academic.sesiones_talleres')) {
            return;
        }

        Schema::connection(config('database.default'))->create('academic.sesiones_talleres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('taller_id');
            $table->foreign('taller_id')->references('id')->on('academic.talleres')->onDelete('cascade');
            $table->date('fecha_sesion');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('aula', 100)->nullable();
            $table->string('tema', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('taller_id');
            $table->index('fecha_sesion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->dropIfExists('academic.sesiones_talleres');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->create('ops.tareas_staff', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->uuid('persona_id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('persona_id')->references('id')->on('people.personas');

            $table->index('persona_id');
            $table->index('estado');
        });

        DB::connection('pgsql')->statement("
            ALTER TABLE ops.tareas_staff
            ADD CONSTRAINT tareas_staff_estado_check
            CHECK (estado IN ('pendiente','en_progreso','completada','cancelada'))
        ");
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('ops.tareas_staff');
    }
};

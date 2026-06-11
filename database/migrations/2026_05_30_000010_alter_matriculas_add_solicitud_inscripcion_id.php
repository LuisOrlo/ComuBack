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
        Schema::connection('pgsql')->table('academic.matriculas', function (Blueprint $table) {
            $table->uuid('solicitud_inscripcion_id')->nullable();
            
            $table->foreign('solicitud_inscripcion_id')
                ->references('id')
                ->on('academic.solicitudes_inscripcion')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->table('academic.matriculas', function (Blueprint $table) {
            $table->dropForeignKey(['solicitud_inscripcion_id']);
            $table->dropColumn('solicitud_inscripcion_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE finance.cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_edicion_video_id_fkey');

        Schema::connection('pgsql')->table('finance.cuentas_por_cobrar', function (Blueprint $table) {
            $table->foreign('edicion_video_id')
                ->references('id')->on('services.trabajos_edicion')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE services.asignaciones_personal DROP CONSTRAINT IF EXISTS asignaciones_personal_edicion_video_id_fkey');

        if (Schema::connection('pgsql')->hasTable('services.asignaciones_personal')) {
            Schema::connection('pgsql')->table('services.asignaciones_personal', function (Blueprint $table) {
                $table->foreign('edicion_video_id')
                    ->references('id')->on('services.trabajos_edicion')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE finance.cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_edicion_video_id_fkey');
        DB::statement('ALTER TABLE services.asignaciones_personal DROP CONSTRAINT IF EXISTS asignaciones_personal_edicion_video_id_fkey');
    }
};

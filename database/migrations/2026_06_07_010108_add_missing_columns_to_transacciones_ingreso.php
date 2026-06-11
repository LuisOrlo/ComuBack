<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql')->statement("DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 't_estado_verificacion') THEN
                CREATE TYPE finance.t_estado_verificacion AS ENUM ('pendiente', 'aprobado', 'rechazado');
            END IF;
        END $$;");

        Schema::connection('pgsql')->table('finance.transacciones_ingreso', function (Blueprint $table) {
            $table->text('observaciones')->nullable();
            $table->string('estado_verificacion', 20)->default('pendiente');
            $table->uuid('verificado_por')->nullable();
            $table->timestamp('fecha_verificacion')->nullable();
            $table->text('motivo_rechazo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('finance.transacciones_ingreso', function (Blueprint $table) {
            $table->dropColumn(['observaciones', 'estado_verificacion', 'verificado_por', 'fecha_verificacion', 'motivo_rechazo']);
        });
    }
};

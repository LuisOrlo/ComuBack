<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('pgsql')->hasTable('services.equipos')) {
            Schema::connection('pgsql')->create('services.equipos', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->string('nombre', 200);
                $table->text('descripcion')->nullable();
                $table->string('foto_url', 500)->nullable();
                $table->decimal('precio_diario', 10, 2)->default(0);
                $table->string('estado', 20)->default('disponible');
                $table->timestampsTz();
            });

            DB::connection('pgsql')->statement("
                ALTER TABLE services.equipos
                ADD CONSTRAINT equipos_estado_check
                CHECK (estado IN ('disponible','alquilado','mantenimiento'))
            ");
        }

        if (!Schema::connection('pgsql')->hasTable('services.alquiler_equipos')) {
            Schema::connection('pgsql')->create('services.alquiler_equipos', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('equipo_id');
                $table->uuid('persona_id')->nullable();
                $table->uuid('cliente_externo_id')->nullable();
                $table->dateTimeTz('fecha_entrega');
                $table->dateTimeTz('fecha_devolucion_esperada');
                $table->dateTimeTz('fecha_recepcion')->nullable();
                $table->string('foto_salida_url', 500)->nullable();
                $table->string('foto_retorno_url', 500)->nullable();
                $table->text('observaciones')->nullable();
                $table->decimal('precio_total', 10, 2);
                $table->string('estado', 20)->default('activo');
                $table->timestampsTz();

                $table->foreign('equipo_id')->references('id')->on('services.equipos');
                $table->foreign('persona_id')->references('id')->on('people.personas')->nullOnDelete();
                $table->foreign('cliente_externo_id')->references('id')->on('people.clientes_externos')->nullOnDelete();

                $table->index('equipo_id');
                $table->index('estado');
            });

            DB::connection('pgsql')->statement("
                ALTER TABLE services.alquiler_equipos
                ADD CONSTRAINT alquiler_equipos_estado_check
                CHECK (estado IN ('activo','devuelto','vencido'))
            ");

            DB::connection('pgsql')->statement("
                ALTER TABLE services.alquiler_equipos
                ADD CONSTRAINT alquiler_equipos_cliente_check
                CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
            ");
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('services.alquiler_equipos');
        Schema::connection('pgsql')->dropIfExists('services.equipos');
    }
};

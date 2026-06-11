<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('pgsql')->hasTable('services.paquetes_podcast')) {
            Schema::connection('pgsql')->create('services.paquetes_podcast', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->string('nombre', 200);
                $table->text('descripcion')->nullable();
                $table->decimal('precio_por_hora', 10, 2)->default(0);
                $table->jsonb('items')->default('[]');
                $table->boolean('activo')->default(true);
                $table->timestampsTz();
            });
        }

        if (!Schema::connection('pgsql')->hasTable('services.reservas_podcast')) {
            Schema::connection('pgsql')->create('services.reservas_podcast', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('paquete_id');
                $table->uuid('persona_id')->nullable();
                $table->uuid('cliente_externo_id')->nullable();
                $table->uuid('editor_id')->nullable();
                $table->date('fecha_reserva');
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->decimal('precio_total', 10, 2)->default(0);
                $table->boolean('pago_registrado')->default(false);
                $table->string('estado', 20)->default('pendiente');
                $table->text('notas')->nullable();
                $table->timestampsTz();

                $table->foreign('paquete_id')->references('id')->on('services.paquetes_podcast');
                $table->foreign('persona_id')->references('id')->on('people.personas')->nullOnDelete();
                $table->foreign('cliente_externo_id')->references('id')->on('people.clientes_externos')->nullOnDelete();

                $table->index('fecha_reserva');
                $table->index('estado');
            });

            DB::connection('pgsql')->statement("
                ALTER TABLE services.reservas_podcast
                ADD CONSTRAINT reservas_podcast_estado_check
                CHECK (estado IN ('pendiente','confirmado','en_progreso','completado','cancelado'))
            ");

            DB::connection('pgsql')->statement("
                ALTER TABLE services.reservas_podcast
                ADD CONSTRAINT reservas_podcast_cliente_check
                CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
            ");
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('services.reservas_podcast');
        Schema::connection('pgsql')->dropIfExists('services.paquetes_podcast');
    }
};

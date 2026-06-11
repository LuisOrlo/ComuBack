<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('pgsql')->hasTable('services.trabajos_edicion')) {
            Schema::connection('pgsql')->create('services.trabajos_edicion', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->string('titulo', 300);
                $table->text('descripcion')->nullable();
                $table->date('fecha_recibo');
                $table->date('fecha_limite');
                $table->date('fecha_entrega')->nullable();
                $table->string('nivel', 20)->default('basica');
                $table->string('estado', 20)->default('recibido');
                $table->jsonb('editor_ids')->default('[]');
                $table->uuid('reserva_podcast_id')->nullable();
                $table->decimal('precio_cobrado', 10, 2)->nullable();
                $table->boolean('cobro_registrado')->default(false);
                $table->text('notas')->nullable();
                $table->timestampsTz();

                $table->foreign('reserva_podcast_id')->references('id')->on('services.reservas_podcast')->nullOnDelete();

                $table->index('estado');
                $table->index('fecha_limite');
                $table->index('fecha_recibo');
            });

            DB::connection('pgsql')->statement("
                ALTER TABLE services.trabajos_edicion
                ADD CONSTRAINT trabajos_edicion_estado_check
                CHECK (estado IN ('recibido','en_proceso','revision','entregado'))
            ");

            DB::connection('pgsql')->statement("
                ALTER TABLE services.trabajos_edicion
                ADD CONSTRAINT trabajos_edicion_nivel_check
                CHECK (nivel IN ('basica','estandar','premium'))
            ");
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('services.trabajos_edicion');
    }
};

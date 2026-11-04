<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabla de Catálogo de Sets (services.sets)
        if (!Schema::connection('pgsql')->hasTable('services.sets')) {
            Schema::connection('pgsql')->create('services.sets', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->string('nombre', 150);
                $table->decimal('precio_hora', 10, 2)->default(0);
                $table->text('descripcion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestampsTz();
            });
        }

        // 2. Tabla de Reservas de Sets (services.reservas_sets)
        if (!Schema::connection('pgsql')->hasTable('services.reservas_sets')) {
            Schema::connection('pgsql')->create('services.reservas_sets', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('set_id');
                $table->uuid('persona_id')->nullable();
                $table->uuid('cliente_externo_id')->nullable();
                $table->date('fecha_reserva');
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->decimal('precio_total', 10, 2)->default(0);
                $table->decimal('precio_original', 10, 2)->nullable();
                $table->decimal('monto_descuento', 10, 2)->default(0);
                $table->string('motivo_descuento', 255)->nullable();
                $table->decimal('monto_recargo', 10, 2)->default(0);
                $table->string('motivo_recargo', 255)->nullable();
                $table->string('estado', 20)->default('reservado');
                $table->text('observaciones')->nullable();
                $table->timestampsTz();

                $table->foreign('set_id')->references('id')->on('services.sets')->onDelete('cascade');
                $table->foreign('persona_id')->references('id')->on('people.personas')->nullOnDelete();
                $table->foreign('cliente_externo_id')->references('id')->on('people.clientes_externos')->nullOnDelete();

                $table->index('fecha_reserva');
                $table->index('estado');
                $table->index('cliente_externo_id');
                $table->index(['set_id', 'fecha_reserva', 'hora_inicio', 'hora_fin', 'estado'], 'idx_reservas_sets_disp');
            });

            DB::connection('pgsql')->statement("
                ALTER TABLE services.reservas_sets
                ADD CONSTRAINT reservas_sets_estado_check
                CHECK (estado IN ('reservado','confirmado','en_progreso','completado','cancelado'))
            ");

            DB::connection('pgsql')->statement("
                ALTER TABLE services.reservas_sets
                ADD CONSTRAINT reservas_sets_cliente_check
                CHECK (num_nonnulls(persona_id, cliente_externo_id) = 1)
            ");
        }

        // 3. Columna reserva_set_id en finance.cuentas_por_cobrar
        if (Schema::connection('pgsql')->hasTable('finance.cuentas_por_cobrar')) {
            if (!Schema::connection('pgsql')->hasColumn('finance.cuentas_por_cobrar', 'reserva_set_id')) {
                Schema::connection('pgsql')->table('finance.cuentas_por_cobrar', function (Blueprint $table) {
                    $table->uuid('reserva_set_id')->nullable();
                    $table->foreign('reserva_set_id')->references('id')->on('services.reservas_sets')->nullOnDelete();
                    $table->index('reserva_set_id');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::connection('pgsql')->hasTable('finance.cuentas_por_cobrar')) {
            if (Schema::connection('pgsql')->hasColumn('finance.cuentas_por_cobrar', 'reserva_set_id')) {
                Schema::connection('pgsql')->table('finance.cuentas_por_cobrar', function (Blueprint $table) {
                    $table->dropForeign(['reserva_set_id']);
                    $table->dropIndex(['reserva_set_id']);
                    $table->dropColumn('reserva_set_id');
                });
            }
        }

        Schema::connection('pgsql')->dropIfExists('services.reservas_sets');
        Schema::connection('pgsql')->dropIfExists('services.sets');
    }
};

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
        Schema::table('services.servicios_streaming', function (Blueprint $table) {
            $table->bigInteger('ciudad_id')->nullable()->after('cliente_externo_id');
            $table->string('titulo', 255)->nullable()->after('ciudad_id');
            $table->string('direccion', 500)->nullable()->after('lugar');
            $table->text('referencias_ubicacion')->nullable()->after('direccion');
            $table->text('equipos_detalle')->nullable()->after('referencias_ubicacion');
            $table->text('observaciones')->nullable()->after('descripcion');
            $table->decimal('precio_original', 10, 2)->nullable()->after('observaciones');
            $table->decimal('monto_descuento', 10, 2)->default(0)->after('precio_original');
            $table->string('motivo_descuento', 255)->nullable()->after('monto_descuento');
            $table->decimal('monto_recargo', 10, 2)->default(0)->after('motivo_descuento');
            $table->string('motivo_recargo', 255)->nullable()->after('monto_recargo');
            $table->timestampTz('updated_at')->nullable()->useCurrent()->after('created_at');

            $table->foreign('ciudad_id')
                ->references('id')
                ->on('core.ciudades')
                ->nullOnDelete();

            $table->index(['fecha_evento', 'hora_inicio', 'hora_fin'], 'idx_servicios_streaming_fechas');
            $table->index('ciudad_id', 'idx_servicios_streaming_ciudad');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services.servicios_streaming', function (Blueprint $table) {
            $table->dropForeign(['ciudad_id']);
            $table->dropIndex('idx_servicios_streaming_fechas');
            $table->dropIndex('idx_servicios_streaming_ciudad');

            $table->dropColumn([
                'ciudad_id',
                'titulo',
                'direccion',
                'referencias_ubicacion',
                'equipos_detalle',
                'observaciones',
                'precio_original',
                'monto_descuento',
                'motivo_descuento',
                'monto_recargo',
                'motivo_recargo',
                'updated_at',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('pgsql')->hasTable('finance.lineas_pago_modulo')) {
            return;
        }
        Schema::create('finance.lineas_pago_modulo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('matricula_id')->constrained('academic.matriculas')->onDelete('cascade');
            $table->foreignUuid('modulo_id')->constrained('academic.modulos')->onDelete('restrict');
            $table->decimal('monto_original', 10, 2);
            $table->decimal('monto_ajustado', 10, 2);
            $table->string('motivo_ajuste', 255)->nullable();
            $table->foreignUuid('ajustado_por')->nullable()->constrained('people.personas');
            $table->timestampTz('fecha_ajuste')->nullable();
            $table->decimal('monto_abonado', 10, 2)->default(0);
            $table->string('estado', 20)->default('pendiente');
            $table->integer('orden')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('matricula_id');
            $table->index('modulo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance.lineas_pago_modulo');
    }
};

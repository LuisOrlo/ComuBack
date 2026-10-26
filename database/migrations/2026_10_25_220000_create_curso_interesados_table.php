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
        Schema::create('curso_interesados', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relación con Curso Abierto (aplica a regulares y personalizados)
            $table->uuid('curso_abierto_id')->nullable();
            $table->foreign('curso_abierto_id')
                ->references('id')
                ->on('cursos_abiertos')
                ->onDelete('cascade');

            // Relación con Taller
            $table->uuid('taller_id')->nullable();
            $table->foreign('taller_id')
                ->references('id')
                ->on('talleres')
                ->onDelete('cascade');

            // Datos del interesado
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('telefono', 30);
            $table->string('cedula', 20)->nullable();
            $table->string('correo', 150)->nullable();

            // Seguimiento comercial / anticipo tentativo
            $table->decimal('monto_pago_anticipo', 10, 2)->default(0.00);
            $table->text('comprobante_url')->nullable();
            $table->text('observaciones')->nullable();

            // Auditoría interna
            $table->foreignId('creado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Índices para búsquedas y optimización
            $table->index('curso_abierto_id');
            $table->index('taller_id');
            $table->index('cedula');
            $table->index('telefono');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curso_interesados');
    }
};

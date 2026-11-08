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
        $connection = config('database.default');

        // 1. Agregar a people.perfil_estudiante si la tabla existe
        if (Schema::connection($connection)->hasTable('people.perfil_estudiante')) {
            Schema::connection($connection)->table('people.perfil_estudiante', function (Blueprint $table) use ($connection) {
                $cols = collect(Schema::connection($connection)->getColumnListing('people.perfil_estudiante'));
                if (!$cols->contains('terminos_aceptados')) {
                    $table->boolean('terminos_aceptados')->default(false);
                }
                if (!$cols->contains('fecha_terminos_aceptados')) {
                    $table->timestamp('fecha_terminos_aceptados')->nullable();
                }
            });
        }

        // 2. Agregar a people.clientes_externos si la tabla existe
        if (Schema::connection($connection)->hasTable('people.clientes_externos')) {
            Schema::connection($connection)->table('people.clientes_externos', function (Blueprint $table) use ($connection) {
                $cols = collect(Schema::connection($connection)->getColumnListing('people.clientes_externos'));
                if (!$cols->contains('terminos_aceptados')) {
                    $table->boolean('terminos_aceptados')->default(false);
                }
                if (!$cols->contains('fecha_terminos_aceptados')) {
                    $table->timestamp('fecha_terminos_aceptados')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = config('database.default');

        if (Schema::connection($connection)->hasTable('people.perfil_estudiante')) {
            Schema::connection($connection)->table('people.perfil_estudiante', function (Blueprint $table) {
                $table->dropColumn(['terminos_aceptados', 'fecha_terminos_aceptados']);
            });
        }

        if (Schema::connection($connection)->hasTable('people.clientes_externos')) {
            Schema::connection($connection)->table('people.clientes_externos', function (Blueprint $table) {
                $table->dropColumn(['terminos_aceptados', 'fecha_terminos_aceptados']);
            });
        }
    }
};

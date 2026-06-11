<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Remover el campo 'codigo' de la tabla catalogo_cursos
     * Este campo ya no es requerido por el sistema
     */
    public function up(): void
    {
        Schema::connection('pgsql')->table('academic.catalogo_cursos', function (Blueprint $table) {
            // Remover índice único si existe
            $table->dropUnique(['codigo']);
            // Remover columna
            $table->dropColumn('codigo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->table('academic.catalogo_cursos', function (Blueprint $table) {
            // Restaurar columna con las mismas propiedades
            $table->string('codigo', 50)->after('id')->unique();
        });
    }
};

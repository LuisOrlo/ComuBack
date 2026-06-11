<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Hacer dia_semana nullable en la tabla horarios
        DB::statement('ALTER TABLE academic.horarios ALTER COLUMN dia_semana DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restaurar la columna a NOT NULL (solo si todos los valores son no-nulos)
        DB::statement('ALTER TABLE academic.horarios ALTER COLUMN dia_semana SET NOT NULL');
    }
};

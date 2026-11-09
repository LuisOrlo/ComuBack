<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.default');

        // 1. Agregar a academic.cursos_abiertos
        $cursosColumns = collect(Schema::connection($connection)->getColumnListing('academic.cursos_abiertos'));
        Schema::connection($connection)->table('academic.cursos_abiertos', function (Blueprint $table) use ($cursosColumns) {
            if (!$cursosColumns->contains('recordatorios_config')) {
                $table->jsonb('recordatorios_config')->nullable();
            }
        });

        // 2. Agregar a academic.talleres
        $talleresColumns = collect(Schema::connection($connection)->getColumnListing('academic.talleres'));
        Schema::connection($connection)->table('academic.talleres', function (Blueprint $table) use ($talleresColumns) {
            if (!$talleresColumns->contains('recordatorios_config')) {
                $table->jsonb('recordatorios_config')->nullable();
            }
        });
    }

    public function down(): void
    {
        $connection = config('database.default');

        Schema::connection($connection)->table('academic.cursos_abiertos', function (Blueprint $table) {
            $table->dropColumn('recordatorios_config');
        });

        Schema::connection($connection)->table('academic.talleres', function (Blueprint $table) {
            $table->dropColumn('recordatorios_config');
        });
    }
};

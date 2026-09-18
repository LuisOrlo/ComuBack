<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection()->getName();

        if (!Schema::connection($connection)->hasColumn('academic.cursos_abiertos', 'catalogo_curso_id')) {
            return;
        }

        if ($connection === 'pgsql') {
            DB::connection($connection)->statement(
                'ALTER TABLE academic.cursos_abiertos ALTER COLUMN catalogo_curso_id DROP NOT NULL'
            );
            return;
        }

        Schema::connection($connection)->table('academic.cursos_abiertos', function ($table) {
            $table->uuid('catalogo_curso_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $connection = Schema::getConnection()->getName();

        if (!Schema::connection($connection)->hasColumn('academic.cursos_abiertos', 'catalogo_curso_id')) {
            return;
        }

        if (DB::connection($connection)->table('academic.cursos_abiertos')->whereNull('catalogo_curso_id')->exists()) {
            throw new RuntimeException(
                'No se puede restaurar NOT NULL en catalogo_curso_id mientras existan cursos personalizados sin catálogo.'
            );
        }

        if ($connection === 'pgsql') {
            DB::connection($connection)->statement(
                'ALTER TABLE academic.cursos_abiertos ALTER COLUMN catalogo_curso_id SET NOT NULL'
            );
            return;
        }

        Schema::connection($connection)->table('academic.cursos_abiertos', function ($table) {
            $table->uuid('catalogo_curso_id')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection()->getName();

        if (!Schema::connection($connection)->hasTable('academic.cursos_abiertos')) {
            return;
        }

        if (!Schema::connection($connection)->hasColumn('academic.cursos_abiertos', 'es_personalizado')) {
            Schema::connection($connection)->table('academic.cursos_abiertos', function (Blueprint $table) {
                $table->boolean('es_personalizado')->nullable()->default(false)->after('catalogo_curso_id');
                $table->index('es_personalizado', 'idx_cursos_abiertos_es_personalizado');
            });
        }

        DB::connection($connection)->table('academic.cursos_abiertos')
            ->whereNull('es_personalizado')
            ->update(['es_personalizado' => false]);

        if ($connection === 'pgsql') {
            DB::connection($connection)->statement(<<<'SQL'
                UPDATE academic.cursos_abiertos ca
                SET es_personalizado = CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM academic.catalogo_cursos cc
                        WHERE cc.id = ca.catalogo_curso_id
                          AND cc.categoria = 'personalizado'
                    ) THEN TRUE
                    ELSE FALSE
                END
                SQL
            );
            DB::connection($connection)->statement(<<<'SQL'
                ALTER TABLE academic.cursos_abiertos
                ALTER COLUMN es_personalizado SET DEFAULT FALSE,
                ALTER COLUMN es_personalizado SET NOT NULL
                SQL
            );
        }
    }

    public function down(): void
    {
        $connection = Schema::getConnection()->getName();
        if (!Schema::connection($connection)->hasColumn('academic.cursos_abiertos', 'es_personalizado')) {
            return;
        }

        if ($connection === 'pgsql') {
            DB::connection($connection)->statement(
                'DROP INDEX IF EXISTS academic.idx_cursos_abiertos_es_personalizado'
            );
        } else {
            Schema::connection($connection)->table('academic.cursos_abiertos', function (Blueprint $table) {
                $table->dropIndex('idx_cursos_abiertos_es_personalizado');
            });
        }

        Schema::connection($connection)->table('academic.cursos_abiertos', function (Blueprint $table) {
            $table->dropColumn('es_personalizado');
        });
    }
};

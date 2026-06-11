<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.default');

        DB::connection($connection)->statement('
            ALTER TABLE academic.catalogo_cursos
            DROP CONSTRAINT IF EXISTS catalogo_cursos_categoria_check
        ');

        DB::connection($connection)->statement('
            ALTER TABLE academic.catalogo_cursos
            ADD CONSTRAINT catalogo_cursos_categoria_check
            CHECK (categoria IN (\'regular\', \'personalizado\', \'taller\'))
        ');
    }

    public function down(): void
    {
        $connection = config('database.default');

        DB::connection($connection)->statement('
            ALTER TABLE academic.catalogo_cursos
            DROP CONSTRAINT IF EXISTS catalogo_cursos_categoria_check
        ');

        DB::connection($connection)->statement('
            ALTER TABLE academic.catalogo_cursos
            ADD CONSTRAINT catalogo_cursos_categoria_check
            CHECK (categoria IN (\'regular\', \'personalizado\'))
        ');
    }
};

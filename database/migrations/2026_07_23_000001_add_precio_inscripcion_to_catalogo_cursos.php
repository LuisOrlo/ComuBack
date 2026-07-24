<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.default');
        $columns = collect(Schema::connection($connection)->getColumnListing('academic.catalogo_cursos'));

        Schema::connection($connection)->table('academic.catalogo_cursos', function (Blueprint $table) use ($columns) {
            if (!$columns->contains('precio_inscripcion')) {
                $table->decimal('precio_inscripcion', 10, 2)->default(0)->after('horas_totales');
            }
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))->table('academic.catalogo_cursos', function (Blueprint $table) {
            $table->dropColumn('precio_inscripcion');
        });
    }
};

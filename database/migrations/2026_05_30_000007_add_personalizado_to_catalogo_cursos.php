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
        $columns = collect(Schema::connection($connection)->getColumnListing('academic.catalogo_cursos'));

        Schema::connection($connection)->table('academic.catalogo_cursos', function (Blueprint $table) use ($columns) {
            if (!$columns->contains('categoria')) {
                $table->enum('categoria', ['regular', 'personalizado'])->default('regular');
            }
            if (!$columns->contains('requisitos_previos')) {
                $table->text('requisitos_previos')->nullable()->after('descripcion');
            }
            if (!$columns->contains('categoria_index')) {
                // Evitar duplicar índices
                try {
                    $table->index('categoria');
                } catch (\Exception $e) {
                    // Índice ya existe, ignorar
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database.default'))->table('academic.catalogo_cursos', function (Blueprint $table) {
            $table->dropIndex(['categoria']);
            $table->dropColumn(['categoria', 'requisitos_previos']);
        });
    }
};

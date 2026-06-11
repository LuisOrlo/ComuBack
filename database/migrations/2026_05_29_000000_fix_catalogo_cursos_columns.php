<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega las columnas faltantes a catalogo_cursos que el modelo espera.
     * Debe ejecutarse ANTES de las migraciones que crean índices en estas columnas.
     */
    public function up(): void
    {
        $connection = config('database.default');
        $columns = collect(Schema::connection($connection)->getColumnListing('academic.catalogo_cursos'));

        Schema::connection($connection)->table('academic.catalogo_cursos', function (Blueprint $table) use ($columns) {
            if (!$columns->contains('programa_id')) {
                $table->uuid('programa_id')->nullable()->after('id');
            }
            if (!$columns->contains('codigo')) {
                $table->string('codigo', 50)->nullable()->unique()->after('programa_id');
            }
            if (!$columns->contains('creditos')) {
                $table->integer('creditos')->default(3)->after('descripcion');
            }
            if (!$columns->contains('horas_totales')) {
                $table->integer('horas_totales')->default(40)->after('creditos');
            }
            if (!$columns->contains('es_activo')) {
                $table->boolean('es_activo')->default(true);
            }
            if (!$columns->contains('created_at')) {
                $table->timestamps();
            }
            if (!$columns->contains('deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))->table('academic.catalogo_cursos', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropTimestamps();
            $table->dropColumn(['es_activo', 'horas_totales', 'creditos', 'codigo', 'programa_id']);
        });
    }
};

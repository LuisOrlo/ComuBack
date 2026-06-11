<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('academic.notas', function ($table) {
            if (Schema::connection('pgsql')->hasColumn('academic.notas', 'nota')) {
                $table->renameColumn('nota', 'calificacion');
            }
            if (!Schema::connection('pgsql')->hasColumn('academic.notas', 'observaciones')) {
                $table->text('observaciones')->nullable()->after('calificacion');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('academic.notas', function ($table) {
            if (Schema::connection('pgsql')->hasColumn('academic.notas', 'calificacion')) {
                $table->renameColumn('calificacion', 'nota');
            }
            if (Schema::connection('pgsql')->hasColumn('academic.notas', 'observaciones')) {
                $table->dropColumn('observaciones');
            }
        });
    }
};

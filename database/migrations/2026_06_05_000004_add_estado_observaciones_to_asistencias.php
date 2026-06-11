<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('academic.asistencias', function ($table) {
            if (!Schema::connection('pgsql')->hasColumn('academic.asistencias', 'estado')) {
                $table->string('estado', 20)->nullable()->after('asistio');
            }
            if (!Schema::connection('pgsql')->hasColumn('academic.asistencias', 'observaciones')) {
                $table->text('observaciones')->nullable()->after('estado');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('academic.asistencias', function ($table) {
            $table->dropColumn(['estado', 'observaciones']);
        });
    }
};

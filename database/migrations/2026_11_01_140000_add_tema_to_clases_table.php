<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('academic.clases', function ($table) {
            if (!Schema::connection('pgsql')->hasColumn('academic.clases', 'tema')) {
                $table->string('tema', 255)->nullable()->after('observaciones');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('academic.clases', function ($table) {
            if (Schema::connection('pgsql')->hasColumn('academic.clases', 'tema')) {
                $table->dropColumn('tema');
            }
        });
    }
};

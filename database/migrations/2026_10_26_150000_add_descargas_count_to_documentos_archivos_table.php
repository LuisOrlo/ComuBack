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
        Schema::table('documentos_archivos', function (Blueprint $table) {
            $table->unsignedInteger('descargas_count')->default(0)->after('es_publico');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documentos_archivos', function (Blueprint $table) {
            $table->dropColumn('descargas_count');
        });
    }
};

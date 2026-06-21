<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('database.default'))->table('academic.talleres', function (Blueprint $table) {
            $table->date('fecha_fin')->nullable()->after('fecha');
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))->table('academic.talleres', function (Blueprint $table) {
            $table->dropColumn('fecha_fin');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('database.default'))
            ->table('academic.inscripciones_taller', function (Blueprint $table) {
                $table->string('motivo_ajuste', 255)->nullable()->after('monto_pagado');
            });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))
            ->table('academic.inscripciones_taller', function (Blueprint $table) {
                $table->dropColumn('motivo_ajuste');
            });
    }
};

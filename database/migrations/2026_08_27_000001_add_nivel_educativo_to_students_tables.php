<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('database.default'))->table('people.perfil_estudiante', function (Blueprint $table) {
            $table->string('nivel_educativo', 100)->nullable();
        });

        Schema::connection(config('database.default'))->table('people.clientes_externos', function (Blueprint $table) {
            $table->string('nivel_educativo', 100)->nullable();
        });

        Schema::connection(config('database.default'))->table('academic.inscripciones_taller', function (Blueprint $table) {
            $table->string('nivel_educativo', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))->table('people.perfil_estudiante', function (Blueprint $table) {
            $table->dropColumn('nivel_educativo');
        });

        Schema::connection(config('database.default'))->table('people.clientes_externos', function (Blueprint $table) {
            $table->dropColumn('nivel_educativo');
        });

        Schema::connection(config('database.default'))->table('academic.inscripciones_taller', function (Blueprint $table) {
            $table->dropColumn('nivel_educativo');
        });
    }
};
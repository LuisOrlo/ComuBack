<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic.inscripciones_taller', function (Blueprint $table) {
            $table->dropColumn('fecha_nacimiento');
        });

        Schema::table('people.clientes_externos', function (Blueprint $table) {
            $table->dropColumn('fecha_nacimiento');
        });

        Schema::table('people.perfil_estudiante', function (Blueprint $table) {
            $table->dropColumn('fecha_nacimiento');
        });
    }

    public function down(): void
    {
        Schema::table('academic.inscripciones_taller', function (Blueprint $table) {
            $table->date('fecha_nacimiento')->nullable()->after('estado_civil');
        });

        Schema::table('people.clientes_externos', function (Blueprint $table) {
            $table->date('fecha_nacimiento')->nullable()->after('fecha_pago');
        });

        Schema::table('people.perfil_estudiante', function (Blueprint $table) {
            $table->date('fecha_nacimiento')->nullable()->after('persona_id');
        });
    }
};

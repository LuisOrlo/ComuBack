<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic.catalogo_cursos', function (Blueprint $table) {
            $table->string('imagen', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('academic.catalogo_cursos', function (Blueprint $table) {
            $table->string('imagen', 500)->nullable()->change();
        });
    }
};

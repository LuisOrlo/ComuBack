<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic.matriculas', function (Blueprint $table) {
            $table->renameColumn('precio_total', 'precio_total_legacy');
        });
    }

    public function down(): void
    {
        Schema::table('academic.matriculas', function (Blueprint $table) {
            $table->renameColumn('precio_total_legacy', 'precio_total');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('people.perfil_instructor', function (Blueprint $table) {
            $table->string('hoja_vida_path', 500)->nullable();
            $table->string('hoja_vida_nombre_original', 255)->nullable();
            $table->string('hoja_vida_mime', 100)->nullable();
            $table->unsignedBigInteger('hoja_vida_size')->nullable();
            $table->timestamp('hoja_vida_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('people.perfil_instructor', function (Blueprint $table) {
            $table->dropColumn([
                'hoja_vida_path',
                'hoja_vida_nombre_original',
                'hoja_vida_mime',
                'hoja_vida_size',
                'hoja_vida_updated_at',
            ]);
        });
    }
};

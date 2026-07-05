<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archivos_eliminados', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('model_type', 255);
            $table->uuid('model_id');
            $table->string('field_name', 100);
            $table->string('file_path', 500);
            $table->string('accion', 20);
            $table->uuid('eliminado_por')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['model_type', 'model_id']);
            $table->index('field_name');
            $table->index('eliminado_por');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archivos_eliminados');
    }
};

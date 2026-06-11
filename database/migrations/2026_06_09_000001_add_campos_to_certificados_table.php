<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::table('academic.certificados', function (Blueprint $table) {
            $table->string('archivo_pdf_url', 500)->nullable()->after('codigo_certificado');
            $table->string('estado', 20)->default('generado')->after('archivo_pdf_url');
            $table->date('fecha_entrega')->nullable()->after('estado');
            $table->boolean('entregado_fisicamente')->default(false)->after('fecha_entrega');
            $table->unsignedInteger('verificaciones_count')->default(0)->after('entregado_fisicamente');
            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
            $table->index('cedula_impresa');
        });
    }

    public function down(): void
    {
        Schema::table('academic.certificados', function (Blueprint $table) {
            $table->dropColumn([
                'archivo_pdf_url',
                'estado',
                'fecha_entrega',
                'entregado_fisicamente',
                'verificaciones_count',
                'created_at',
                'updated_at',
                'deleted_at',
            ]);
        });
    }
};

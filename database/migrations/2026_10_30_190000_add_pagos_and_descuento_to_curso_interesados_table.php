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
        Schema::table('curso_interesados', function (Blueprint $table) {
            $table->decimal('descuento', 10, 2)->default(0.00)->after('monto_pago_anticipo');
            $table->string('motivo_descuento', 255)->nullable()->after('descuento');
            $table->decimal('precio_final', 10, 2)->nullable()->after('motivo_descuento');
            $table->jsonb('pagos')->nullable()->after('precio_final');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('curso_interesados', function (Blueprint $table) {
            $table->dropColumn(['descuento', 'motivo_descuento', 'precio_final', 'pagos']);
        });
    }
};

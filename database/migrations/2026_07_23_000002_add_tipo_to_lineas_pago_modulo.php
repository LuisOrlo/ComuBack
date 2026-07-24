<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('pgsql')->hasTable('finance.lineas_pago_modulo')) {
            return;
        }

        $columns = collect(Schema::connection('pgsql')->getColumnListing('finance.lineas_pago_modulo'));

        Schema::connection('pgsql')->table('finance.lineas_pago_modulo', function (Blueprint $table) use ($columns) {
            if (!$columns->contains('tipo')) {
                $table->string('tipo', 20)->default('modulo')->after('modulo_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('pgsql')->hasTable('finance.lineas_pago_modulo')) {
            return;
        }

        Schema::connection('pgsql')->table('finance.lineas_pago_modulo', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};

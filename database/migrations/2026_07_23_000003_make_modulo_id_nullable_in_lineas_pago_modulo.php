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

        Schema::connection('pgsql')->table('finance.lineas_pago_modulo', function (Blueprint $table) {
            $table->uuid('modulo_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::connection('pgsql')->hasTable('finance.lineas_pago_modulo')) {
            return;
        }

        Schema::connection('pgsql')->table('finance.lineas_pago_modulo', function (Blueprint $table) {
            $table->uuid('modulo_id')->nullable(false)->change();
        });
    }
};

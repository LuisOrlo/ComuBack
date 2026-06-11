<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql')->statement('ALTER TABLE finance.transacciones_ingreso ALTER COLUMN comprobante_url TYPE TEXT');
        DB::connection('pgsql')->statement('ALTER TABLE finance.transacciones_egreso ALTER COLUMN comprobante_url TYPE TEXT');
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('ALTER TABLE finance.transacciones_ingreso ALTER COLUMN comprobante_url TYPE VARCHAR(500)');
        DB::connection('pgsql')->statement('ALTER TABLE finance.transacciones_egreso ALTER COLUMN comprobante_url TYPE VARCHAR(500)');
    }
};

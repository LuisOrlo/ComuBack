<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_created_at ON finance.cuentas_por_cobrar (created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cuentas_estado ON finance.cuentas_por_cobrar (estado)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_created_at');
        DB::statement('DROP INDEX IF EXISTS finance.idx_cuentas_estado');
    }
};

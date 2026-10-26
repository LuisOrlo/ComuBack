<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE curso_interesados DROP CONSTRAINT IF EXISTS curso_interesados_creado_por_id_foreign');
        DB::statement('ALTER TABLE curso_interesados ALTER COLUMN creado_por_id TYPE uuid USING NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS curso_interesados_creado_por_id_idx ON curso_interesados (creado_por_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE curso_interesados ALTER COLUMN creado_por_id TYPE bigint USING NULL');
    }
};

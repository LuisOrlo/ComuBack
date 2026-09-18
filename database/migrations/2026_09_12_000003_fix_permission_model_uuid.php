<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // CuentaSistema utiliza UUID; la tabla de permisos se creó por error
        // con model_id bigint y provoca conversiones inválidas en cada check.
        DB::statement('ALTER TABLE core.model_has_permissions ALTER COLUMN model_id TYPE uuid USING model_id::text::uuid');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE core.model_has_permissions ALTER COLUMN model_id TYPE bigint USING model_id::text::bigint');
    }
};

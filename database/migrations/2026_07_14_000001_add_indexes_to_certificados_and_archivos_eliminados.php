<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection('pgsql');

        // 1. GIN trigram index for ilike search on codigo_certificado
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_certificados_codigo_trgm ON academic.certificados USING gin (codigo_certificado public.gin_trgm_ops)');

        // 2. Btree index on created_at (used in orderBy)
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_certificados_created_at ON academic.certificados USING btree (created_at)');

        // 3. Conditional unique index on codigo_certificado (excludes soft-deleted)
        $connection->statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_certificados_codigo_unique ON academic.certificados USING btree (codigo_certificado) WHERE (deleted_at IS NULL)');

        // 4. Composite index on archivos_eliminados for the optimized lookup subquery
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_archivos_eliminados_lookup ON core.archivos_eliminados USING btree (model_type, model_id, field_name, created_at DESC)');
    }

    public function down(): void
    {
        $connection = DB::connection('pgsql');

        $connection->statement('DROP INDEX IF EXISTS academic.idx_certificados_codigo_trgm');
        $connection->statement('DROP INDEX IF EXISTS academic.idx_certificados_created_at');
        $connection->statement('DROP INDEX IF EXISTS academic.idx_certificados_codigo_unique');
        $connection->statement('DROP INDEX IF EXISTS core.idx_archivos_eliminados_lookup');
    }
};

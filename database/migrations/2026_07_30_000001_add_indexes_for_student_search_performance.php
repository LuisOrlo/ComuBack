<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection('pgsql');

        // 1. B-tree index on nombres for ORDER BY in student listing
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_personas_nombres_btree ON people.personas USING btree (nombres)');

        // 2. Index on estado for inscripciones_taller (filtered in WHERE)
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_inscripciones_taller_estado ON academic.inscripciones_taller USING btree (estado)');

        // 3. B-tree index on nombres for ORDER BY in workshop participants listing
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_inscripciones_taller_nombres ON academic.inscripciones_taller USING btree (nombres)');

        // 4. Index on cedula for LIKE search in inscripciones_taller
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_inscripciones_taller_cedula ON academic.inscripciones_taller USING btree (cedula)');

        // 5. GIN trigram index on nombres for texto search in inscripciones_taller
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_inscripciones_taller_nombres_trgm ON academic.inscripciones_taller USING gin (nombres public.gin_trgm_ops)');

        // 6. GIN trigram index on apellidos for texto search in inscripciones_taller
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_inscripciones_taller_apellidos_trgm ON academic.inscripciones_taller USING gin (apellidos public.gin_trgm_ops)');

        // 7. Index on participante_externo_id for the whereHas subquery with ClienteExterno
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_solicitudes_inscripcion_participante_externo_id ON academic.solicitudes_inscripcion USING btree (participante_externo_id)');
    }

    public function down(): void
    {
        $connection = DB::connection('pgsql');

        $connection->statement('DROP INDEX IF EXISTS people.idx_personas_nombres_btree');
        $connection->statement('DROP INDEX IF EXISTS academic.idx_inscripciones_taller_estado');
        $connection->statement('DROP INDEX IF EXISTS academic.idx_inscripciones_taller_nombres');
        $connection->statement('DROP INDEX IF EXISTS academic.idx_inscripciones_taller_cedula');
        $connection->statement('DROP INDEX IF EXISTS academic.idx_inscripciones_taller_nombres_trgm');
        $connection->statement('DROP INDEX IF EXISTS academic.idx_inscripciones_taller_apellidos_trgm');
        $connection->statement('DROP INDEX IF EXISTS academic.idx_solicitudes_inscripcion_participante_externo_id');
    }
};

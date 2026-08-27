<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        $connection = DB::connection('pgsql');

        // La extensión ya se crea en DB.sql; IF NOT EXISTS lo hace idempotente.
        $connection->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');

        // Índices trigram para búsquedas ILIKE '%...%' (nombres y apellidos)
        // de personas y clientes externos. Nombres idénticos a los de DB.sql
        // para no duplicar índices en entornos ya provisionados.
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_personas_nombres_trgm ON people.personas USING gin (nombres public.gin_trgm_ops)');
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_personas_apellidos_trgm ON people.personas USING gin (apellidos public.gin_trgm_ops)');
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_clientes_externos_nombres ON people.clientes_externos USING gin (nombres public.gin_trgm_ops)');
        $connection->statement('CREATE INDEX IF NOT EXISTS idx_clientes_externos_apellidos ON people.clientes_externos USING gin (apellidos public.gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            return;
        }

        $connection = DB::connection('pgsql');

        $connection->statement('DROP INDEX IF EXISTS people.idx_personas_nombres_trgm');
        $connection->statement('DROP INDEX IF EXISTS people.idx_personas_apellidos_trgm');
        $connection->statement('DROP INDEX IF EXISTS people.idx_clientes_externos_nombres');
        $connection->statement('DROP INDEX IF EXISTS people.idx_clientes_externos_apellidos');
    }
};

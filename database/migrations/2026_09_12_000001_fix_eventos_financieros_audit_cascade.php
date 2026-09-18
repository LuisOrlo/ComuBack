<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("
                DO \$\$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM information_schema.table_constraints 
                        WHERE constraint_name = 'eventos_financieros_transaccion_ingreso_id_fkey'
                        AND table_schema = 'audit'
                    ) THEN
                        ALTER TABLE audit.eventos_financieros DROP CONSTRAINT eventos_financieros_transaccion_ingreso_id_fkey;
                        ALTER TABLE audit.eventos_financieros 
                            ADD CONSTRAINT eventos_financieros_transaccion_ingreso_id_fkey 
                            FOREIGN KEY (transaccion_ingreso_id) REFERENCES finance.transacciones_ingreso(id) ON DELETE RESTRICT;
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM information_schema.table_constraints 
                        WHERE constraint_name = 'eventos_financieros_transaccion_egreso_id_fkey'
                        AND table_schema = 'audit'
                    ) THEN
                        ALTER TABLE audit.eventos_financieros DROP CONSTRAINT eventos_financieros_transaccion_egreso_id_fkey;
                        ALTER TABLE audit.eventos_financieros 
                            ADD CONSTRAINT eventos_financieros_transaccion_egreso_id_fkey 
                            FOREIGN KEY (transaccion_egreso_id) REFERENCES finance.transacciones_egreso(id) ON DELETE RESTRICT;
                    END IF;
                END \$\$;
            ");
        }
    }

    public function down(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("
                DO \$\$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM information_schema.table_constraints 
                        WHERE constraint_name = 'eventos_financieros_transaccion_ingreso_id_fkey'
                        AND table_schema = 'audit'
                    ) THEN
                        ALTER TABLE audit.eventos_financieros DROP CONSTRAINT eventos_financieros_transaccion_ingreso_id_fkey;
                        ALTER TABLE audit.eventos_financieros 
                            ADD CONSTRAINT eventos_financieros_transaccion_ingreso_id_fkey 
                            FOREIGN KEY (transaccion_ingreso_id) REFERENCES finance.transacciones_ingreso(id) ON DELETE CASCADE;
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM information_schema.table_constraints 
                        WHERE constraint_name = 'eventos_financieros_transaccion_egreso_id_fkey'
                        AND table_schema = 'audit'
                    ) THEN
                        ALTER TABLE audit.eventos_financieros DROP CONSTRAINT eventos_financieros_transaccion_egreso_id_fkey;
                        ALTER TABLE audit.eventos_financieros 
                            ADD CONSTRAINT eventos_financieros_transaccion_egreso_id_fkey 
                            FOREIGN KEY (transaccion_egreso_id) REFERENCES finance.transacciones_egreso(id) ON DELETE CASCADE;
                    END IF;
                END \$\$;
            ");
        }
    }
};

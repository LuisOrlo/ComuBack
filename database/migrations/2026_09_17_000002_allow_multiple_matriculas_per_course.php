<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'academic.matriculas';
    private const UNIQUE_NAME = 'uq_estudiante_curso';
    private const INDEX_NAME = 'idx_matriculas_estudiante_curso';

    public function up(): void
    {
        $constraint = DB::selectOne(
            "select 1
             from pg_constraint
             where conrelid = '" . self::TABLE . "'::regclass
               and conname = ?
               and contype = 'u'",
            [self::UNIQUE_NAME]
        );

        if ($constraint) {
            DB::statement('alter table ' . self::TABLE . ' drop constraint ' . self::UNIQUE_NAME);
        } else {
            // Compatibilidad con instalaciones donde se creó como índice único
            // independiente en lugar de constraint.
            $uniqueIndex = DB::selectOne(
                "select 1
                 from pg_class idx
                 join pg_index i on i.indexrelid = idx.oid
                 where idx.relname = ?
                   and i.indrelid = '" . self::TABLE . "'::regclass
                   and i.indisunique = true",
                [self::UNIQUE_NAME]
            );

            if ($uniqueIndex) {
                DB::statement('drop index ' . self::UNIQUE_NAME);
            }
        }

        $equivalentIndex = DB::selectOne(
            "select 1
             from pg_index i
             join pg_attribute a1 on a1.attrelid = i.indrelid and a1.attnum = i.indkey[0]
             join pg_attribute a2 on a2.attrelid = i.indrelid and a2.attnum = i.indkey[1]
             where i.indrelid = '" . self::TABLE . "'::regclass
               and i.indisvalid = true
               and i.indisunique = false
               and i.indnatts = 2
               and a1.attname = 'estudiante_id'
               and a2.attname = 'curso_abierto_id'"
        );

        if (!$equivalentIndex) {
            DB::statement('create index ' . self::INDEX_NAME . ' on ' . self::TABLE . ' (estudiante_id, curso_abierto_id)');
        }
    }

    public function down(): void
    {
        $duplicates = DB::selectOne(
            "select 1
             from " . self::TABLE . "
             group by estudiante_id, curso_abierto_id
             having count(*) > 1
             limit 1"
        );

        if ($duplicates) {
            throw new RuntimeException(
                'No se puede restaurar uq_estudiante_curso porque existen múltiples matrículas históricas para la misma oferta.'
            );
        }

        $index = DB::selectOne(
            "select 1 from pg_class where relname = ? and relkind = 'i'",
            [self::INDEX_NAME]
        );
        if ($index) {
            DB::statement('drop index ' . self::INDEX_NAME);
        }

        DB::statement(
            'alter table ' . self::TABLE
            . ' add constraint ' . self::UNIQUE_NAME
            . ' unique (estudiante_id, curso_abierto_id)'
        );
    }
};

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncMigrations extends Command
{
    protected $signature = 'db:sync-migrations
                            {--dry-run : Solo mostrar qué migraciones se marcarían, sin ejecutar}';

    protected $description = 'Sincroniza la tabla migrations con los archivos existentes, marcando como ejecutadas las migraciones cuyas tablas/columnas ya existen en la BD. Evita el error "Duplicate table" al correr migrate sobre una BD preexistente.';

    public function handle(): int
    {
        $files = File::files(database_path('migrations'));
        $ran = DB::table('migrations')->pluck('migration')->toArray();

        $toMark = [];

        foreach ($files as $file) {
            $name = $file->getFilenameWithoutExtension();

            if (in_array($name, $ran)) {
                continue;
            }

            $objects = $this->extractSchemaObjects($file->getPathname());

            if (empty($objects)) {
                $this->line("  <fg=gray>? {$name}</> — no se pudo determinar qué objetos crea, se omitirá");
                continue;
            }

            $allExist = true;
            $missing = [];

            foreach ($objects as $obj) {
                if (! $this->objectExists($obj['type'], $obj['name'], $obj['schema'] ?? 'public')) {
                    $allExist = false;
                    $missing[] = "{$obj['type']} {$obj['name']}";
                }
            }

            if ($allExist && count($objects) > 0) {
                $toMark[] = $name;
                $this->line("  <fg=green>✓ {$name}</> — todos los objetos ya existen");
            } else {
                $this->line("  <fg=yellow>✗ {$name}</> — faltan: " . implode(', ', $missing) . ' — se dejará Pending');
            }
        }

        if (empty($toMark)) {
            $this->info("\nTodas las migraciones están sincronizadas. Nada que hacer.");
            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("\n[DRY RUN] Se marcarían " . count($toMark) . " migraciones como ejecutadas.");
            return Command::SUCCESS;
        }

        $maxBatch = (int) (DB::table('migrations')->max('batch') ?? 0) + 1;
        foreach ($toMark as $name) {
            DB::table('migrations')->insert([
                'migration' => $name,
                'batch' => $maxBatch,
            ]);
        }

        $this->info("\n✓ " . count($toMark) . " migraciones marcadas como ejecutadas (batch {$maxBatch}).");
        $this->info('Ahora puedes correr `php artisan migrate` sin errores.');

        return Command::SUCCESS;
    }

    /**
     * Extrae los objetos de esquema (tablas, columnas, índices, vistas) que crea una migración.
     */
    private function extractSchemaObjects(string $path): array
    {
        $content = File::get($path);
        $objects = [];

        // Schema::create('schema.table', ...) o Schema::create('table', ...)
        if (preg_match_all("/Schema::create\s*\(\s*['\"](?:([a-z_]+)\.)?([a-z_]+)['\"]/i", $content, $m)) {
            for ($i = 0; $i < count($m[0]); $i++) {
                $schema = $m[1][$i] ?: 'public';
                $table = $m[2][$i];
                $objects[] = ['type' => 'table', 'name' => $table, 'schema' => $schema];
            }
        }

        // Schema::table('schema.table', ...) con ->addColumn(...)
        if (preg_match_all("/Schema::table\s*\(\s*['\"](?:([a-z_]+)\.)?([a-z_]+)['\"]/i", $content, $tableMatches, PREG_SET_ORDER)) {
            foreach ($tableMatches as $tm) {
                $schema = $tm[1] ?: 'public';
                $tableName = $tm[2];

                // Buscar ->addColumn('nombre_columna', ...) en el callback de esa tabla
                if (preg_match_all('/\$table->\w+\(\s*[\'"](\w+)[\'"]/', $content, $colMatches)) {
                    foreach ($colMatches[1] as $col) {
                        $objects[] = ['type' => 'column', 'name' => "{$tableName}.{$col}", 'schema' => $schema];
                    }
                }
            }
        }

        // Schema::rename() – no crea objetos, lo ignoramos
        // Migraciones que solo hacen DELETE/INSERT/UPDATE – no crean objetos de esquema

        // DB::statement('CREATE VIEW ...')
        if (preg_match_all("/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+(?:([a-z_]+)\.)?([a-z_]+)/i", $content, $viewMatches)) {
            for ($i = 0; $i < count($viewMatches[0]); $i++) {
                $schema = $viewMatches[1][$i] ?: 'public';
                $view = $viewMatches[2][$i];
                $objects[] = ['type' => 'view', 'name' => $view, 'schema' => $schema];
            }
        }

        return $objects;
    }

    /**
     * Verifica si un objeto existe en PostgreSQL.
     */
    private function objectExists(string $type, string $name, string $schema = 'public'): bool
    {
        return match ($type) {
            'table' => DB::table('information_schema.tables')
                ->where('table_schema', $schema)
                ->where('table_name', $name)
                ->exists(),
            'column' => (function () use ($name, $schema) {
                $parts = explode('.', $name, 2);
                if (count($parts) !== 2) return false;
                return DB::table('information_schema.columns')
                    ->where('table_schema', $schema)
                    ->where('table_name', $parts[0])
                    ->where('column_name', $parts[1])
                    ->exists();
            })(),
            'view' => DB::table('information_schema.views')
                ->where('table_schema', $schema)
                ->where('table_name', $name)
                ->exists(),
            default => false,
        };
    }
}

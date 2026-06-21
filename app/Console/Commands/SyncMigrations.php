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
                $schema = $obj['schema'] ?? null;
                if (! $this->objectExists($obj['type'], $obj['name'], $schema)) {
                    $allExist = false;
                    $label = $obj['schema'] ? "{$obj['schema']}.{$obj['name']}" : $obj['name'];
                    $missing[] = "{$obj['type']} {$label}";
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

    // ===================================================================
    // Métodos que CREAN columnas (no dropColumn, foreign, index, unique, etc.)
    // ===================================================================
    private const COLUMN_METHODS = 'string|integer|bigInteger|smallInteger|tinyInteger|'
        . 'unsignedBigInteger|unsignedInteger|unsignedSmallInteger|unsignedTinyInteger|'
        . 'decimal|float|double|boolean|date|dateTime|dateTimeTz|time|timeTz|'
        . 'timestamp|timestampTz|timestamps|timestampsTz|softDeletes|softDeletesTz|'
        . 'text|mediumText|longText|json|jsonb|uuid|binary|enum|set|'
        . 'char|year|geometry|geography|macAddress|ipAddress|'
        . 'morphs|nullableMorphs|uuidMorphs|nullableUuidMorphs|'
        . 'rememberToken|id|bigIncrements|addColumn|renameColumn';

    /**
     * Extrae los objetos de esquema (tablas, columnas, vistas, triggers, funciones)
     * que crea una migración.
     */
    private function extractSchemaObjects(string $path): array
    {
        $content = File::get($path);
        $objects = [];

        // Extraer SOLO el método up() para evitar falsos positivos de down()
        $upBody = $this->extractUpMethodBody($content);
        if ($upBody === null) {
            return $objects;
        }

        // Normalizar: remover Schema::connection(...)-> para unificar patrones
        // Esto maneja connection('pgsql'), connection(config('database.default')), etc.
        $normalized = $this->normalizeConnectionChains($upBody);

        // Resolver variables de configuración como $tableNames = config('permission.table_names')
        $configVars = $this->resolveConfigVariables($upBody);

        // ================================================================
        // 1. Schema::create('schema.table', ...)
        // ================================================================
        if (preg_match_all(
            "/Schema::create\s*\(\s*['\"](?:([a-z_]+)\.)?([a-z_]+)['\"]/i",
            $normalized, $m, PREG_SET_ORDER
        )) {
            foreach ($m as $match) {
                $schema = $match[1] ?: null;
                $table = $match[2];
                $objects[] = ['type' => 'table', 'name' => $table, 'schema' => $schema];
            }
        }

        // Schema::create($varName['key'], ...) — variables de configuración
        if (preg_match_all(
            "/Schema::create\s*\(\s*\\$(\w+)\s*\[\s*['\"](\w+)['\"]\s*\]/i",
            $normalized, $varMatches, PREG_SET_ORDER
        )) {
            foreach ($varMatches as $vm) {
                $varName = $vm[1];
                $key = $vm[2];
                if (isset($configVars[$varName]) && is_array($configVars[$varName])) {
                    $table = $configVars[$varName][$key] ?? null;
                    if ($table && is_string($table)) {
                        // El valor puede ser 'table' o 'schema.table'
                        $parts = explode('.', $table, 2);
                        if (count($parts) === 2) {
                            $objects[] = ['type' => 'table', 'name' => $parts[1], 'schema' => $parts[0]];
                        } else {
                            $objects[] = ['type' => 'table', 'name' => $table, 'schema' => null];
                        }
                    }
                }
            }
        }

        // ================================================================
        // 2. Schema::table('schema.table', ...) — columnas añadidas
        //    Usamos scoping: extraemos columnas solo del bloque de cada table()
        // ================================================================
        if (preg_match_all(
            "/Schema::table\s*\(\s*['\"](?:([a-z_]+)\.)?([a-z_]+)['\"]/i",
            $normalized, $tableMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            $schemaMatches = $this->findAllSchemaCalls($normalized);

            foreach ($tableMatches as $idx => $tm) {
                $schema = $tm[1][0] ?: null;
                $tableName = $tm[2][0];
                $startPos = $tm[0][1];

                // Alcance: desde este Schema::table hasta el siguiente Schema:: o fin
                $endPos = strlen($normalized);
                foreach ($schemaMatches as $sm) {
                    if ($sm['pos'] > $startPos) {
                        $endPos = $sm['pos'];
                        break;
                    }
                }
                $block = substr($normalized, $startPos, $endPos - $startPos);

                $cols = $this->extractColumnsFromBlock($block, $tableName, $schema);
                $objects = array_merge($objects, $cols);
            }
        }

        // Schema::table($varName['key'], ...) — variables de configuración
        if (preg_match_all(
            "/Schema::table\s*\(\s*\\$(\w+)\s*\[\s*['\"](\w+)['\"]\s*\]/i",
            $normalized, $varTableMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            $schemaMatches = $this->findAllSchemaCalls($normalized);

            foreach ($varTableMatches as $vtm) {
                $varName = $vtm[1][0];
                $key = $vtm[2][0];
                if (isset($configVars[$varName]) && is_array($configVars[$varName])) {
                    $resolvedTable = $configVars[$varName][$key] ?? null;
                    if ($resolvedTable && is_string($resolvedTable)) {
                        // El valor puede ser 'table' o 'schema.table'
                        $parts = explode('.', $resolvedTable, 2);
                        $tableName = count($parts) === 2 ? $parts[1] : $resolvedTable;
                        $tableSchema = count($parts) === 2 ? $parts[0] : null;

                        $startPos = $vtm[0][1];
                        $endPos = strlen($normalized);
                        foreach ($schemaMatches as $sm) {
                            if ($sm['pos'] > $startPos) {
                                $endPos = $sm['pos'];
                                break;
                            }
                        }
                        $block = substr($normalized, $startPos, $endPos - $startPos);

                        $cols = $this->extractColumnsFromBlock($block, $tableName, $tableSchema);
                        $objects = array_merge($objects, $cols);
                    }
                }
            }
        }

        // ================================================================
        // 3. DB::statement('CREATE OR REPLACE FUNCTION ...')
        //    DB::statement('CREATE TRIGGER ...')
        //    DB::statement('CREATE INDEX ...')
        // ================================================================
        if (preg_match_all(
            "/DB::statement\s*\(\s*['\"](.*?)['\"]\s*\)/si",
            $normalized, $stmtMatches, PREG_SET_ORDER
        )) {
            foreach ($stmtMatches as $sm) {
                $sql = $sm[1];

                // CREATE OR REPLACE FUNCTION schema.name
                if (preg_match_all(
                    '/CREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+(?:([a-z_]+)\.)?([a-z_]+)/i',
                    $sql, $func
                )) {
                    for ($i = 0; $i < count($func[0]); $i++) {
                        $schema = $func[1][$i] ?: null;
                        $name = $func[2][$i];
                        $objects[] = ['type' => 'function', 'name' => $name, 'schema' => $schema];
                    }
                }

                // CREATE TRIGGER name
                if (preg_match_all(
                    '/CREATE\s+(?:OR\s+REPLACE\s+)?TRIGGER\s+(\w+)/i',
                    $sql, $trig
                )) {
                    foreach ($trig[1] as $name) {
                        $objects[] = ['type' => 'trigger', 'name' => $name, 'schema' => null];
                    }
                }

                // CREATE INDEX [IF NOT EXISTS] [schema.]name
                if (preg_match_all(
                    '/CREATE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:([a-z_]+)\.)?(\w+)/i',
                    $sql, $idx
                )) {
                    for ($i = 0; $i < count($idx[0]); $i++) {
                        $schema = $idx[1][$i] ?: null;
                        $name = $idx[2][$i];
                        $objects[] = ['type' => 'index', 'name' => $name, 'schema' => $schema];
                    }
                }
            }
        }

        // ================================================================
        // 4. DB::statement('CREATE OR REPLACE VIEW ...')
        // ================================================================
        if (preg_match_all(
            "/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+(?:([a-z_]+)\.)?(\w+)/i",
            $upBody, $viewMatches
        )) {
            for ($i = 0; $i < count($viewMatches[0]); $i++) {
                $schema = $viewMatches[1][$i] ?: null;
                $view = $viewMatches[2][$i];
                $objects[] = ['type' => 'view', 'name' => $view, 'schema' => $schema];
            }
        }

        // ================================================================
        // 5. DB::statement('CREATE TYPE schema.type_name ...')
        // ================================================================
        if (preg_match_all(
            "/CREATE\s+TYPE\s+(?:([a-z_]+)\.)?(\w+)/i",
            $upBody, $typeMatches
        )) {
            for ($i = 0; $i < count($typeMatches[0]); $i++) {
                $schema = $typeMatches[1][$i] ?: null;
                $type = $typeMatches[2][$i];
                $objects[] = ['type' => 'type', 'name' => $type, 'schema' => $schema];
            }
        }

        // ================================================================
        // 6. DB::statement('ALTER TABLE ... ADD CONSTRAINT ...')
        //    Detectamos constraints nuevas
        // ================================================================
        if (preg_match_all(
            "/ALTER\s+TABLE\s+(?:([a-z_]+)\.)?(\w+)\s+ADD\s+CONSTRAINT\s+(\w+)/i",
            $upBody, $constraintMatches
        )) {
            for ($i = 0; $i < count($constraintMatches[0]); $i++) {
                $schema = $constraintMatches[1][$i] ?: null;
                $constraintName = $constraintMatches[3][$i];
                $objects[] = ['type' => 'constraint', 'name' => $constraintName, 'schema' => $schema];
            }
        }

        return $objects;
    }

    /**
     * Extrae SOLO el cuerpo del método up() de la migración.
     */
    private function extractUpMethodBody(string $content): ?string
    {
        // Buscar "function up(): void" o "function up()" o "public function up()"
        if (! preg_match(
            '/(?:public\s+)?function\s+up\s*\(\s*\)\s*(?::\s*\w+\s*)?\s*\{/',
            $content, $m, PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $startPos = $m[0][1] + strlen($m[0][0]);
        $braceCount = 1;
        $pos = $startPos;
        $len = strlen($content);

        while ($pos < $len && $braceCount > 0) {
            $ch = $content[$pos];
            if ($ch === '{') {
                $braceCount++;
            } elseif ($ch === '}') {
                $braceCount--;
            }
            $pos++;
        }

        return substr($content, $startPos, $pos - $startPos - 1);
    }

    /**
     * Normaliza Schema::connection(...)->... a Schema::...
     * para que los regex puedan detectar los patrones uniformemente.
     */
    /**
     * Normaliza Schema::connection(...)->... a Schema::... y
     * DB::connection(...)->... a DB::... para unificar patrones.
     * Usa .*? no-greedy con flag s para manejar paréntesis anidados.
     */
    private function normalizeConnectionChains(string $content): string
    {
        // Normalizar Schema::connection(...)-> a Schema::
        $content = preg_replace(
            '/Schema::connection\s*\(.*?\)->/is',
            'Schema::',
            $content
        );
        // Normalizar DB::connection(...)-> a DB::
        $content = preg_replace(
            '/DB::connection\s*\(.*?\)->/is',
            'DB::',
            $content
        );
        return $content;
    }

    /**
     * Encuentra todas las posiciones de Schema::create/table en el contenido
     * para delimitar los bloques.
     */
    private function findAllSchemaCalls(string $content): array
    {
        $positions = [];
        if (preg_match_all(
            '/Schema::(?:create|table|dropIfExists|drop)\s*\(/i',
            $content, $m, PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[0] as $match) {
                $positions[] = ['pos' => $match[1], 'call' => $match[0]];
            }
        }
        return $positions;
    }

    /**
     * Extrae columnas del bloque de un Schema::table() específico.
     */
    private function extractColumnsFromBlock(string $block, string $tableName, ?string $schema): array
    {
        $objects = [];

        if (preg_match_all(
            '/\$table->(' . self::COLUMN_METHODS . ')\(\s*[\'"](\w+)[\'"]/',
            $block, $colMatches
        )) {
            foreach ($colMatches[2] as $colIdx => $col) {
                $method = $colMatches[1][$colIdx];
                $columnName = $col;

                if ($method === 'renameColumn') {
                    // Para renameColumn, el NUEVO nombre es el segundo argumento
                    if (preg_match(
                        '/\$table->renameColumn\(\s*[\'"]\w+[\'"]\s*,\s*[\'"](\w+)[\'"]/i',
                        $block, $renameMatch
                    )) {
                        $columnName = $renameMatch[1];
                    }
                }

                $objects[] = [
                    'type' => 'column',
                    'name' => "{$tableName}.{$columnName}",
                    'schema' => $schema,
                ];
            }
        }

        return $objects;
    }

    /**
     * Resuelve variables de configuración definidas en la migración,
     * como $tableNames = config('permission.table_names').
     * Retorna un array [varName => value].
     */
    private function resolveConfigVariables(string $content): array
    {
        $vars = [];
        if (preg_match_all(
            "/\\$(\w+)\s*=\s*config\s*\(\s*['\"]([^'\"]+)['\"]\s*\)/",
            $content, $matches, PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $varName = $m[1];
                $configKey = $m[2];
                $vars[$varName] = config($configKey);
            }
        }
        return $vars;
    }

    /**
     * Verifica si un objeto existe en PostgreSQL.
     * Si $schema es null, busca en todos los esquemas del search_path.
     */
    private function objectExists(string $type, string $name, ?string $schema): bool
    {
        $nonSystemSchemas = $this->getNonSystemSchemas();

        return match ($type) {
            'table' => $this->existsInSchemas(
                fn($s) => DB::table('information_schema.tables')
                    ->where('table_schema', $s)
                    ->where('table_name', $name)
                    ->exists(),
                $schema, $nonSystemSchemas
            ),
            'column' => (function () use ($name, $schema, $nonSystemSchemas) {
                $parts = explode('.', $name, 2);
                if (count($parts) !== 2) return false;
                return $this->existsInSchemas(
                    fn($s) => DB::table('information_schema.columns')
                        ->where('table_schema', $s)
                        ->where('table_name', $parts[0])
                        ->where('column_name', $parts[1])
                        ->exists(),
                    $schema, $nonSystemSchemas
                );
            })(),
            'view' => $this->existsInSchemas(
                fn($s) => DB::table('information_schema.views')
                    ->where('table_schema', $s)
                    ->where('table_name', $name)
                    ->exists(),
                $schema, $nonSystemSchemas
            ),
            'type' => $this->existsInSchemas(
                fn($s) => DB::selectOne(
                    "SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE n.nspname = ? AND t.typname = ?",
                    [$s, $name]
                ) !== null,
                $schema, $nonSystemSchemas
            ),
            'function' => $this->existsInSchemas(
                fn($s) => DB::selectOne(
                    "SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = ? AND p.proname = ?",
                    [$s, $name]
                ) !== null,
                $schema, $nonSystemSchemas
            ),
            'trigger' => (function () use ($name) {
                return DB::selectOne(
                    "SELECT 1 FROM pg_trigger WHERE tgname = ?", [$name]
                ) !== null;
            })(),
            'index' => (function () use ($name, $schema, $nonSystemSchemas) {
                return $this->existsInSchemas(
                    fn($s) => DB::selectOne(
                        "SELECT 1 FROM pg_indexes WHERE schemaname = ? AND indexname = ?",
                        [$s, $name]
                    ) !== null,
                    $schema, $nonSystemSchemas
                );
            })(),
            'constraint' => (function () use ($name, $schema, $nonSystemSchemas) {
                return $this->existsInSchemas(
                    fn($s) => DB::selectOne(
                        "SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = ? AND constraint_name = ?",
                        [$s, $name]
                    ) !== null,
                    $schema, $nonSystemSchemas
                );
            })(),
            default => false,
        };
    }

    /**
     * Si $schema es específico, busca solo en ese esquema.
     * Si $schema es null, busca en todos los esquemas no-sistema.
     */
    private function existsInSchemas(callable $check, ?string $schema, array $nonSystemSchemas): bool
    {
        if ($schema !== null) {
            return $check($schema);
        }
        foreach ($nonSystemSchemas as $s) {
            if ($check($s)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Obtiene los esquemas no-sistema visibles.
     */
    private function getNonSystemSchemas(): array
    {
        $rows = DB::select(
            "SELECT schema_name FROM information_schema.schemata
             WHERE schema_name NOT IN ('information_schema', 'pg_catalog', 'pg_toast')
             ORDER BY schema_name"
        );
        return array_map(fn($r) => $r->schema_name, $rows);
    }
}

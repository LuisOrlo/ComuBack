<?php

namespace App\Console\Commands;

use App\Services\StorageCleanupService;
use Illuminate\Console\Command;

class StorageCleanupCommand extends Command
{
    protected $signature = 'storage:cleanup
                            {--days=90 : Antigüedad mínima en días de los registros cuyos archivos se limpiarán}
                            {--model= : Clase del modelo a limpiar (ej: App\\Models\\Certificado)}
                            {--field= : Campo específico del modelo a limpiar}
                            {--dry-run : Solo muestra qué se eliminaría sin ejecutar}';

    protected $description = 'Elimina archivos físicos huérfanos o antiguos del storage, registrando auditoría';

    public function handle(StorageCleanupService $service): int
    {
        $days = (int) $this->option('days');
        $modelClass = $this->option('model');
        $field = $this->option('field');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('MODO SIMULACIÓN: No se eliminará ningún archivo.');
        } else {
            $this->info("Iniciando limpieza de archivos con más de {$days} días...");
        }

        $results = $service->cleanupOlderThan($days, $modelClass, $field, (bool) $dryRun);

        $this->info("Total de registros revisados: {$results['total']}");
        if ($dryRun) {
            $this->info("Candidatos para eliminación: " . count($results['candidatos'] ?? []));
            if (!empty($results['candidatos'])) {
                $this->table(['Modelo', 'ID', 'Campo', 'Eliminado en'], array_map(fn($d) => [
                    $d['model'], $d['id'], $d['field'], $d['deleted_at'],
                ], $results['candidatos']));
            }
        } else {
            $this->info("Archivos eliminados: {$results['eliminados']}");
            $this->warn("Errores: {$results['errores']}");
        }

        if (!empty($results['detalles'])) {
            $this->table(['Modelo', 'ID', 'Campo', 'Error'], array_map(fn($d) => [
                $d['model'], $d['id'], $d['field'], $d['error'],
            ], $results['detalles']));
        }

        return self::SUCCESS;
    }
}

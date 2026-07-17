<?php

namespace App\Services;

use App\Models\ArchivoEliminado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageCleanupService
{
    const ALLOWED_FIELDS = [
        \App\Models\Certificado::class => [
            'archivo_pdf_url' => ['disk' => 's3', 'prefix' => ''],
        ],
        \App\Models\InscripcionTaller::class => [
            'comprobante_url' => ['disk' => 's3', 'prefix' => ''],
            'cedula_url' => ['disk' => 's3', 'prefix' => ''],
        ],
        \App\Models\SolicitudInscripcion::class => [
            'archivo_comprobante_url' => ['disk' => 's3', 'prefix' => ''],
            'archivo_cedula_url' => ['disk' => 's3', 'prefix' => ''],
        ],
        \App\Models\Matricula::class => [
            'voucher_url' => ['disk' => 's3', 'prefix' => ''],
        ],
        \App\Models\Persona::class => [
            'cedula_photo_url' => ['disk' => 's3', 'prefix' => ''],
        ],
        \App\Models\Services\Equipo::class => [
            'foto_url' => ['disk' => 's3', 'prefix' => ''],
        ],
        \App\Models\TransaccionIngreso::class => [
            'comprobante_url' => ['disk' => 's3', 'prefix' => ''],
        ],
        \App\Models\TransaccionEgreso::class => [
            'comprobante_url' => ['disk' => 's3', 'prefix' => ''],
        ],
    ];

    /**
     * Eliminar un archivo físico del storage, registrando auditoría.
     * No modifica el campo path en el modelo.
     */
    public function deleteFile(Model $model, string $field, ?string $eliminadoPor = null, string $accion = ArchivoEliminado::ACCION_BORRADO_ARCHIVO): array
    {
        $config = $this->validateField($model, $field);
        $path = $model->{$field};

        if (empty($path)) {
            return ['eliminado' => false, 'mensaje' => 'El registro no tiene archivo asociado en el campo ' . $field];
        }

        if (ArchivoEliminado::archivoFueEliminado(get_class($model), $model->id, $field)) {
            return ['eliminado' => false, 'mensaje' => 'El archivo ya fue eliminado anteriormente'];
        }

        $storagePath = $this->extractStoragePath($path, $config);
        return DB::transaction(function () use ($model, $field, $config, $storagePath, $eliminadoPor, $accion, $path) {
            if (Storage::disk($config['disk'])->exists($storagePath)) {
                Storage::disk($config['disk'])->delete($storagePath);

                if (Storage::disk($config['disk'])->exists($storagePath)) {
                    Log::error("StorageCleanupService: No se pudo eliminar el archivo {$storagePath}");
                    throw new \RuntimeException("El archivo {$storagePath} no pudo eliminarse del almacenamiento");
                }
            }

            ArchivoEliminado::create([
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'field_name' => $field,
                'file_path' => $path,
                'accion' => $accion,
                'eliminado_por' => $eliminadoPor,
                'created_at' => now(),
            ]);

            Log::info("StorageCleanupService: Archivo eliminado", [
                'model' => get_class($model),
                'model_id' => $model->id,
                'field' => $field,
                'accion' => $accion,
                'eliminado_por' => $eliminadoPor,
            ]);

            return ['eliminado' => true, 'mensaje' => 'Archivo eliminado correctamente'];
        });
    }

    /**
     * Eliminar todos los archivos asociados a un registro cuando se borra el registro completo.
     * Se llama DESPUÉS del soft/hard delete del modelo.
     */
    public function deleteRecordFiles(Model $model, ?string $eliminadoPor = null): void
    {
        $modelClass = get_class($model);

        if (!isset(self::ALLOWED_FIELDS[$modelClass])) {
            return;
        }

        foreach (self::ALLOWED_FIELDS[$modelClass] as $field => $config) {
            $path = $model->{$field};
            if (empty($path)) {
                continue;
            }

            try {
                $this->deleteFile($model, $field, $eliminadoPor, ArchivoEliminado::ACCION_BORRADO_REGISTRO);
            } catch (\Exception $e) {
                Log::error("StorageCleanupService: Error al eliminar archivo de registro", [
                    'model' => $modelClass,
                    'model_id' => $model->id,
                    'field' => $field,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Verificar si un archivo fue eliminado (según la tabla de auditoría).
     */
    public function isFileDeleted(Model $model, string $field): bool
    {
        return ArchivoEliminado::archivoFueEliminado(get_class($model), $model->id, $field);
    }

    /**
     * Verificar si el archivo físico existe en storage.
     */
    public function fileExists(Model $model, string $field): bool
    {
        $config = $this->validateField($model, $field);
        $path = $model->{$field};

        if (empty($path)) {
            return false;
        }

        $storagePath = $this->extractStoragePath($path, $config);        return Storage::disk($config['disk'])->exists($storagePath);
    }

    /**
     * Validar que el campo esté en la whitelist del modelo.
     */
    private function validateField(Model $model, string $field): array
    {
        $modelClass = get_class($model);

        if (!isset(self::ALLOWED_FIELDS[$modelClass])) {
            throw new \InvalidArgumentException("El modelo {$modelClass} no está configurado para eliminación de archivos");
        }

        if (!isset(self::ALLOWED_FIELDS[$modelClass][$field])) {
            throw new \InvalidArgumentException("El campo '{$field}' no está permitido para el modelo {$modelClass}");
        }

        return self::ALLOWED_FIELDS[$modelClass][$field];
    }

    /**
     * Extrae la key de storage desde la URL o path almacenado.
     * Para S3 las URLs son absolutas (https://...), se parsea el path.
     * Para discos locales se usa el prefix configurado.
     */
    private function extractStoragePath(string $path, array $config): string
    {
        if ($config['disk'] === 's3') {
            $parsed = parse_url($path);
            return ltrim($parsed['path'] ?? '', '/');
        }
        return str_replace($config['prefix'], '', $path);
    }

    /**
     * Revivir un campo de archivo: crea un registro en archivos_eliminados
     * con accion 'archivo_restaurado' para que el archivo nuevo sea accesible
     * sin perder el historial de eliminaciones previas.
     * Se usa al re-subir/reemplazar un archivo.
     */
    public function reviveFileField(Model $model, string $field): void
    {
        $path = $model->{$field};

        ArchivoEliminado::create([
            'model_type' => get_class($model),
            'model_id' => $model->id,
            'field_name' => $field,
            'file_path' => $path ?? '',
            'accion' => ArchivoEliminado::ACCION_RESTAURADO,
            'eliminado_por' => auth()->id() ?? auth()->user()?->persona_id ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * Eliminar físicamente un archivo del storage sin registrar auditoría.
     * Útil para reemplazos (uploads) donde no se quiere dejar constancia de
     * cada versión anterior.
     */
    public function deleteFilePhysically(Model $model, string $field): void
    {
        $config = $this->validateField($model, $field);
        $path = $model->{$field};

        if (empty($path)) {
            return;
        }

        $storagePath = $this->extractStoragePath($path, $config);
        if (Storage::disk($config['disk'])->exists($storagePath)) {
            Storage::disk($config['disk'])->delete($storagePath);
        }
    }

    /**
     * Para el comando Artisan: limpiar archivos de registros con más de X días de antigüedad.
     */
    public function cleanupOlderThan(int $days, ?string $modelClass = null, ?string $field = null): array
    {
        $results = ['total' => 0, 'eliminados' => 0, 'errores' => 0, 'detalles' => []];

        $modelsConfig = $modelClass
            ? [$modelClass => self::ALLOWED_FIELDS[$modelClass] ?? []]
            : self::ALLOWED_FIELDS;

        foreach ($modelsConfig as $class => $fields) {
            if ($field && !isset($fields[$field])) {
                continue;
            }

            $fieldsToClean = $field ? [$field => $fields[$field]] : $fields;

            foreach ($fieldsToClean as $fieldName => $config) {
                $query = $class::query();

                if (method_exists($class, 'onlyTrashed')) {
                    $query->onlyTrashed();
                }

                $cutoff = now()->subDays($days);

                $query->whereNotNull($fieldName)
                    ->where($fieldName, '!=', '')
                    ->where('created_at', '<', $cutoff)
                    ->chunkById(100, function ($records) use ($fieldName, $config, &$results) {
                        foreach ($records as $record) {
                            $results['total']++;
                            try {
                                $result = $this->deleteFile($record, $fieldName, null, ArchivoEliminado::ACCION_BORRADO_ARCHIVO);
                                if ($result['eliminado']) {
                                    $results['eliminados']++;
                                }
                            } catch (\Exception $e) {
                                $results['errores']++;
                                $results['detalles'][] = [
                                    'model' => get_class($record),
                                    'id' => $record->id,
                                    'field' => $fieldName,
                                    'error' => $e->getMessage(),
                                ];
                            }
                        }
                    });
            }
        }

        return $results;
    }
}

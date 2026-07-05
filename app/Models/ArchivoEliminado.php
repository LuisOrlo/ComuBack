<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchivoEliminado extends Model
{
    use HasUuids;

    protected $table = 'archivos_eliminados';
    protected $connection = 'pgsql';
    public $timestamps = false;

    const ACCION_BORRADO_ARCHIVO = 'borrado_archivo';
    const ACCION_BORRADO_REGISTRO = 'borrado_registro';
    const ACCION_RESTAURADO = 'archivo_restaurado';

    protected $fillable = [
        'model_type',
        'model_id',
        'field_name',
        'file_path',
        'accion',
        'eliminado_por',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function eliminadoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'eliminado_por', 'id');
    }

    public static function archivoFueEliminado(string $modelType, string $modelId, string $fieldName): bool
    {
        $last = static::where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->where('field_name', $fieldName)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$last) {
            return false;
        }

        return in_array($last->accion, [self::ACCION_BORRADO_ARCHIVO, self::ACCION_BORRADO_REGISTRO]);
    }
}

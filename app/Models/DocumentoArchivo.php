<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\Storage;

class DocumentoArchivo extends Model
{
    use HasUuids;

    protected $table = 'documentos_archivos';

    protected $fillable = [
        'nombre',
        'descripcion',
        'archivo_path',
        'archivo_nombre_original',
        'es_publico',
        'descargas_count',
    ];

    protected $casts = [
        'es_publico' => 'boolean',
        'descargas_count' => 'integer',
    ];

    protected $appends = [
        'archivo_url',
    ];

    /**
     * URL pública para el archivo
     */
    public function getArchivoUrlAttribute(): string
    {
        if (empty($this->archivo_path)) {
            return '';
        }
        return Storage::disk('public')->url($this->archivo_path);
    }

    /**
     * Scope para consultar solo documentos públicos
     */
    public function scopePublico($query)
    {
        return $query->where('es_publico', true);
    }

    /**
     * Scope para búsqueda por coincidencia en nombre o descripción
     */
    public function scopeBuscar($query, ?string $termino)
    {
        if (!empty($termino)) {
            $query->where(function ($q) use ($termino) {
                $q->where('nombre', 'ilike', "%{$termino}%")
                  ->orWhere('descripcion', 'ilike', "%{$termino}%");
            });
        }

        return $query;
    }
}

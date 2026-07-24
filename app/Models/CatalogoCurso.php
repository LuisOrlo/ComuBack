<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class CatalogoCurso extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'academic.catalogo_cursos';
    protected $connection = 'pgsql';
    public $timestamps = true;

    protected $fillable = [
        'programa_id',
        'nombre',
        'descripcion',
        'creditos',
        'horas_totales',
        'precio_inscripcion',
        'modulos_default',
        'es_activo',
        'categoria',
        'imagen',
        'color',
    ];

    protected $casts = [
        'es_activo' => 'boolean',
        'creditos' => 'integer',
        'horas_totales' => 'integer',
        'modulos_default' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Programa académico al que pertenece este catálogo de curso
     */
    public function programa(): BelongsTo
    {
        return $this->belongsTo(Programa::class, 'programa_id', 'id');
    }

    /**
     * Cursos abiertos (instancias) de este catálogo
     * Ej: "Cálculo I 2026-1", "Cálculo I 2026-2"
     */
    public function cursosAbiertos(): HasMany
    {
        return $this->hasMany(CursoAbierto::class, 'catalogo_curso_id', 'id');
    }

    /**
     * Módulos predeterminados del catálogo
     * Cantidad controlada por modulos_default
     */
    public function modulosPredeterminados(): HasMany
    {
        return $this->hasMany(Modulo::class, 'catalogo_curso_id', 'id')
                    ->whereNull('curso_abierto_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo cursos activos
     */
    public function scopeActivos($query)
    {
        return $query->where('es_activo', true);
    }

    /**
     * Solo cursos regulares (no personalizados, no talleres)
     */
    public function scopeRegulares($query)
    {
        return $query->where('categoria', 'regular')
                     ->orWhereNull('categoria');
    }

    /**
     * Solo cursos personalizados
     */
    public function scopePersonalizados($query)
    {
        return $query->where('categoria', 'personalizado');
    }

    /**
     * Solo talleres
     */
    public function scopeTalleres($query)
    {
        return $query->where('categoria', 'taller');
    }

    /**
     * Por programa
     */
    public function scopeDelPrograma($query, $programaId)
    {
        return $query->where('programa_id', $programaId);
    }

    /**
     * Por búsqueda (nombre)
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where('nombre', 'ilike', "%{$termino}%");
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * Obtener todos los módulos (predeterminados + personalizados)
     * para un curso abierto específico
     */
    public function obtenerModulosParaCurso(CursoAbierto $cursoAbierto)
    {
        // Módulos predeterminados del catálogo
        $modulosPredeterminados = $this->modulosPredeterminados()
                                        ->pluck('nombre', 'id');

        // Módulos específicos del curso abierto (si existen)
        $modulosCurso = $cursoAbierto->modulos()->pluck('nombre', 'id');

        return $modulosPredeterminados->merge($modulosCurso);
    }

    /**
     * Validar que el catálogo tenga la estructura correcta
     */
    public function esValido(): bool
    {
        return !empty($this->nombre)
            && $this->modulos_default > 0
            && $this->modulos_default <= 10;
    }

    /**
     * Obtener descripción de categoría
     */
    protected function categoria(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $value ?? 'regular',
        );
    }
}

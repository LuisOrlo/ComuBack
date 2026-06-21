<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Modulo extends Model
{
    use HasUuids;

    protected $table = 'academic.modulos';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'catalogo_curso_id',
        'curso_abierto_id',
        'nombre_modulo',
        'descripcion',
        'numero_orden',
        'fecha_inicio',
        'fecha_fin',
        'semana_inicio',
        'semana_fin',
        'ponderacion',
        'precio_base',
    ];

    protected $casts = [
        'semana_inicio' => 'integer',
        'semana_fin' => 'integer',
        'ponderacion' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Catálogo del módulo (si es predeterminado)
     */
    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(CatalogoCurso::class, 'catalogo_curso_id', 'id');
    }

    /**
     * Curso abierto específico (si es personalizado)
     */
    public function cursoAbierto(): BelongsTo
    {
        return $this->belongsTo(CursoAbierto::class, 'curso_abierto_id', 'id');
    }

    /**
     * Notas asignadas a este módulo
     */
    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class, 'modulo_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Módulos de catálogo (predeterminados)
     */
    public function scopeDelCatalogo($query)
    {
        return $query->whereNotNull('catalogo_curso_id')
                     ->whereNull('curso_abierto_id');
    }

    /**
     * Módulos personalizados (de curso abierto)
     */
    public function scopePersonalizados($query)
    {
        return $query->whereNotNull('curso_abierto_id');
    }

    /**
     * Por catálogo específico
     */
    public function scopeDelCatalogoId($query, $catalogoId)
    {
        return $query->where('catalogo_curso_id', $catalogoId)
                     ->whereNull('curso_abierto_id');
    }

    /**
     * Por curso abierto específico
     */
    public function scopeDelCursoAbierto($query, $cursoAbiertoId)
    {
        return $query->where('curso_abierto_id', $cursoAbiertoId);
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * ¿Es módulo predeterminado del catálogo?
     */
    public function esPredeterminado(): bool
    {
        return $this->catalogo_curso_id !== null && $this->curso_abierto_id === null;
    }

    /**
     * ¿Es módulo personalizado?
     */
    public function esPersonalizado(): bool
    {
        return $this->curso_abierto_id !== null;
    }

    /**
     * Obtener duración en semanas
     */
    public function obtenerDuracionSemanas(): int
    {
        return ($this->semana_fin - $this->semana_inicio) + 1;
    }

    /**
     * ¿Es válido?
     */
    public function esValido(): bool
    {
        return !empty($this->nombre_modulo)
            && $this->semana_inicio > 0
            && $this->semana_fin > 0
            && $this->semana_inicio <= $this->semana_fin
            && $this->ponderacion > 0
            && $this->ponderacion <= 100;
    }

    /**
     * Obtener ponderación como porcentaje
     */
    public function obtenerPonderacionPorcentaje(): string
    {
        return round($this->ponderacion, 2) . '%';
    }

    /**
     * Obtener período (ej: "Semanas 1-4")
     */
    public function obtenerPeriodo(): string
    {
        return "Semanas {$this->semana_inicio}-{$this->semana_fin}";
    }

    /**
     * Contar notas registradas
     */
    public function obtenerCountNotas(): int
    {
        return $this->notas()
                    ->whereNotNull('calificacion')
                    ->count();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class CursoAbierto extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'academic.cursos_abiertos';
    protected $connection = 'pgsql';
    public $timestamps = true;

    protected $fillable = [
        'catalogo_curso_id',
        'nombre_instancia',
        'semestre',
        'fecha_inicio',
        'fecha_fin',
        'capacidad_maxima',
        'docente_id',
        'es_activo',
        'observaciones',
        'modalidad',
        'ciudad_id',
        'horario_id',
        'precio_base',
    ];

    protected $casts = [
        'es_activo' => 'boolean',
        'capacidad_maxima' => 'integer',
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Catálogo del cual proviene este curso abierto
     */
    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(CatalogoCurso::class, 'catalogo_curso_id', 'id');
    }

    /**
     * Docente que dicta este curso
     */
    public function docente(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'docente_id', 'id');
    }

    /**
     * Ciudad donde se dicta este curso
     */
    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class, 'ciudad_id', 'id');
    }

    /**
      * Horario de este curso abierto (BelongsTo: curso tiene un horario_id)
      */
    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'horario_id', 'id');
    }

    /**
     * Módulos específicos de este curso abierto
     */
    public function modulos(): HasMany
    {
        return $this->hasMany(Modulo::class, 'curso_abierto_id', 'id');
    }

    /**
     * Matrículas de estudiantes en este curso
     */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class, 'curso_abierto_id', 'id');
    }

    /**
     * Cambios de horario relacionados a este curso
     */
    public function cambiosHorarioDestino(): HasMany
    {
        return $this->hasMany(CambioHorario::class, 'curso_abierto_nuevo_id', 'id');
    }

    /**
     * Cambios de horario que SALIERON de este curso
     */
    public function cambiosHorarioOrigen(): HasMany
    {
        return $this->hasMany(CambioHorario::class, 'curso_abierto_antiguo_id', 'id');
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
     * Por semestre
     */
    public function scopeDelSemestre($query, $semestre)
    {
        return $query->where('semestre', $semestre);
    }

    /**
     * Por catálogo
     */
    public function scopeDelCatalogo($query, $catalogoId)
    {
        return $query->where('catalogo_id', $catalogoId);
    }

    /**
     * Por docente
     */
    public function scopeDelDocente($query, $docenteId)
    {
        return $query->where('docente_id', $docenteId);
    }

    /**
     * Cursos vigentes (fecha actual entre inicio y fin)
     */
    public function scopeVigentes($query)
    {
        $ahora = Carbon::now();
        return $query->where('fecha_inicio', '<=', $ahora)
                     ->where('fecha_fin', '>=', $ahora)
                     ->where('es_activo', true);
    }

    /**
     * Cursos próximos a iniciar
     */
    public function scopeProximos($query, $dias = 7)
    {
        $ahora = Carbon::now();
        $futuro = $ahora->copy()->addDays($dias);
        
        return $query->whereBetween('fecha_inicio', [$ahora, $futuro])
                     ->where('es_activo', true)
                     ->orderBy('fecha_inicio', 'asc');
    }

    /**
     * Búsqueda por nombre de instancia o catálogo
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where('nombre_instancia', 'ilike', "%{$termino}%")
                     ->orWhereHas('catalogo', function ($q) use ($termino) {
                         $q->where('nombre', 'ilike', "%{$termino}%");
                     });
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * Obtener número de matrículas activas
     */
    public function obtenerCountMatriculas(): int
    {
        return $this->matriculas()
                    ->whereIn('estado', ['activo', 'completado'])
                    ->whereNull('deleted_at')
                    ->count();
    }

    /**
     * Obtener espacios disponibles
     */
    public function obtenerEspaciosDisponibles(): int
    {
        $inscritos = $this->obtenerCountMatriculas();
        $disponibles = max(0, $this->capacidad_maxima - $inscritos);
        
        return $disponibles;
    }

    /**
     * ¿Está lleno?
     */
    public function estaLleno(): bool
    {
        return $this->obtenerEspaciosDisponibles() <= 0;
    }

    /**
     * ¿Hay espacios disponibles?
     */
    public function hayEspacios(): bool
    {
        return !$this->estaLleno();
    }

    /**
     * Porcentaje de ocupación
     */
    public function getPorcentajeOcupacion(): float
    {
        $inscritos = $this->obtenerCountMatriculas();
        
        if ($this->capacidad_maxima == 0) {
            return 0;
        }
        
        return round(($inscritos / $this->capacidad_maxima) * 100, 2);
    }

    /**
     * Obtener período del curso (ej: "2026-1")
     */
    public function getPeriodo(): string
    {
        return $this->semestre ?? 'N/A';
    }

    /**
     * ¿Está en vigencia?
     */
    public function estaVigente(): bool
    {
        $ahora = Carbon::now();
        
        return $this->es_activo
            && $this->fecha_inicio <= $ahora
            && $this->fecha_fin >= $ahora;
    }

    /**
     * Validar estructura del curso abierto
     */
    public function esValido(): bool
    {
        return !empty($this->nombre_instancia)
            && !empty($this->semestre)
            && $this->fecha_inicio <= $this->fecha_fin
            && $this->capacidad_maxima > 0;
    }

    /**
     * Obtener nombre completo (Catálogo + Instancia)
     */
    public function getNombreCompleto(): string
    {
        $catalogo = $this->catalogo ? $this->catalogo->nombre : 'Desconocido';
        return "{$catalogo} ({$this->nombre_instancia})";
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CursoPersonalizado extends CursoAbierto
{
    // El modelo utiliza la misma tabla que CursoAbierto
    protected $table = 'academic.cursos_abiertos';

    // Atributos específicos para cursos personalizados
    protected $fillable = [
        'catalogo_curso_id',
        'es_personalizado',
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
        'dirigido_a',
        'requisitos_especiales',
        'certificado_emitido',
        'costo_unitario',
    ];

    protected $casts = [
        'es_activo' => 'boolean',
        'capacidad_maxima' => 'integer',
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'certificado_emitido' => 'boolean',
        'costo_unitario' => 'decimal:2',
    ];

    // Bootear el modelo para filtrar solo personalizados.
    // La categoría del catálogo se conserva como fallback temporal para
    // registros históricos creados antes de existir es_personalizado.
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('personalizado', function ($query) {
            $query->where(function ($q) {
                $q->where('es_personalizado', true)
                    ->orWhere(function ($legacy) {
                        $legacy->whereNull('es_personalizado')
                            ->whereHas('catalogo', function ($catalogo) {
                                $catalogo->where('categoria', 'personalizado');
                            });
                    });
            });
        });
    }

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Participantes externos en este curso personalizado
     */
    public function participantesExternos(): BelongsToMany
    {
        return $this->belongsToMany(
            ParticipanteExterno::class,
            'academic.participantes_cursos_personalizados',
            'curso_personalizado_id',
            'participante_externo_id'
        )
        ->withPivot('fecha_inscripcion', 'estado')
        ->withTimestamps();
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo cursos con capacidad para externos
     */
    public function scopeAceptaExternos($query)
    {
        return $query->where('acepta_externos', true);
    }

    /**
     * Cursos activos que aceptan inscripciones
     */
    public function scopeAbiertoParaInscripcion($query)
    {
        return $query->where('es_activo', true)
                     ->where('fecha_inicio', '>', now())
                     ->whereColumn('estudiantes_inscritos', '<', 'capacidad_maxima');
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * Obtener total de participantes (estudiantes + externos)
     */
    public function totalParticipantes(): int
    {
        return $this->matriculas()->count() + $this->participantesExternos()->count();
    }

    /**
     * Obtener capacidad disponible
     */
    public function capacidadDisponibleParticipantes(): int
    {
        return max(0, $this->capacidad_maxima - $this->totalParticipantes());
    }

    /**
     * Validar si acepta más inscripciones
     */
    public function aceptaInscripciones(): bool
    {
        return $this->es_activo &&
               $this->fecha_inicio > now()->toDateString() &&
               $this->capacidadDisponibleParticipantes() > 0;
    }

    /**
     * Obtener estadísticas del curso
     */
    public function estadisticas(): array
    {
        $estudiantes = $this->matriculas()->count();
        $externos = $this->participantesExternos()->count();
        $total = $estudiantes + $externos;

        return [
            'estudiantes' => $estudiantes,
            'participantes_externos' => $externos,
            'total_participantes' => $total,
            'capacidad' => $this->capacidad_maxima,
            'tasa_ocupacion' => $this->capacidad_maxima > 0 ? round(($total / $this->capacidad_maxima) * 100, 2) : 0,
            'capacidad_disponible' => $this->capacidadDisponibleParticipantes(),
            'permitir_inscripcion' => $this->aceptaInscripciones(),
        ];
    }

    /**
     * Obtener promedio de notas de todos los participantes
     */
    public function promedioNotas(): float
    {
        $notas = $this->modulos()
                     ->join('academic.notas', 'academic.modulos.id', '=', 'academic.notas.modulo_id')
                     ->pluck('academic.notas.calificacion');

        return $notas->count() > 0 ? round($notas->avg(), 2) : 0;
    }
}

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
    protected $fillable = array_merge(parent::getFillable(), [
        'dirigido_a',  // Descripción de público objetivo
        'requisitos_especiales',  // Requisitos específicos
        'certificado_emitido',  // Si emite certificado
        'costo_unitario',  // Costo por participante (NULL para interno)
    ]);

    protected $casts = array_merge(parent::getCasts(), [
        'certificado_emitido' => 'boolean',
        'costo_unitario' => 'decimal:2',
    ]);

    // Bootear el modelo para filtrar solo personalizados
    protected static function boot()
    {
        parent::boot();

        // Filtrar automáticamente solo cursos personalizados
        static::addGlobalScope('personalizado', function ($query) {
            $query->whereHas('catalogo', function ($q) {
                $q->where('categoria', 'personalizado');
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
        return $query->where('estado', 'abierto')
                     ->where('fecha_inicio', '>', now());
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
        return $this->capacidad - $this->totalParticipantes();
    }

    /**
     * Validar si acepta más inscripciones
     */
    public function aceptaInscripciones(): bool
    {
        return $this->estado === 'abierto' &&
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
            'capacidad' => $this->capacidad,
            'tasa_ocupacion' => $this->capacidad > 0 ? round(($total / $this->capacidad) * 100, 2) : 0,
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

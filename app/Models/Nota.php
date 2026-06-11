<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Nota extends Model
{
    use HasUuids;

    protected $table = 'academic.notas';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'matricula_id',
        'modulo_id',
        'calificacion',
        'observaciones',
    ];

    protected $casts = [
        'calificacion' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = ['created_at', 'updated_at'];

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Matrícula a la que pertenece esta nota
     */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_id', 'id');
    }

    /**
     * Módulo en el que se registra la nota
     */
    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'modulo_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo notas registradas (con calificación)
     */
    public function scopeRegistradas($query)
    {
        return $query->whereNotNull('calificacion');
    }

    /**
     * Solo notas pendientes (sin calificación)
     */
    public function scopePendientes($query)
    {
        return $query->whereNull('calificacion');
    }

    /**
     * Por matrícula
     */
    public function scopeDeMatricula($query, $matriculaId)
    {
        return $query->where('matricula_id', $matriculaId);
    }

    /**
     * Por módulo
     */
    public function scopeDelModulo($query, $moduloId)
    {
        return $query->where('modulo_id', $moduloId);
    }

    /**
     * Notas aprobadas (>= 6.5)
     */
    public function scopeAprobadas($query)
    {
        return $query->whereNotNull('calificacion')
                     ->where('calificacion', '>=', 6.5);
    }

    /**
     * Notas reprobadas (< 6.5)
     */
    public function scopeReprobadas($query)
    {
        return $query->whereNotNull('calificacion')
                     ->where('calificacion', '<', 6.5);
    }

    /**
     * Por estudiante (a través de matrícula)
     */
    public function scopeDelEstudiante($query, $estudianteId)
    {
        return $query->whereHas('matricula', function ($q) use ($estudianteId) {
            $q->where('estudiante_id', $estudianteId);
        });
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * ¿Está registrada la nota?
     */
    public function estaRegistrada(): bool
    {
        return $this->calificacion !== null;
    }

    /**
     * ¿Está pendiente?
     */
    public function estaPendiente(): bool
    {
        return $this->calificacion === null;
    }

    /**
     * ¿Está aprobada?
     */
    public function estaAprobada(): bool
    {
        return $this->calificacion !== null && $this->calificacion >= 6.5;
    }

    /**
     * ¿Está reprobada?
     */
    public function estaReprobada(): bool
    {
        return $this->calificacion !== null && $this->calificacion < 6.5;
    }

    /**
     * Obtener descripción de estado
     */
    public function obtenerDescripcionEstado(): string
    {
        if ($this->estaPendiente()) {
            return 'Pendiente';
        }

        return $this->estaAprobada() ? 'Aprobada' : 'Reprobada';
    }

    /**
     * ¿Es válida la nota?
     */
    public function esValida(): bool
    {
        return $this->calificacion === null
            || ($this->calificacion >= 0 && $this->calificacion <= 10.0);
    }

    /**
     * Obtener representación visual de la nota
     */
    public function obtenerRepresentacionVisual(): string
    {
        if ($this->estaPendiente()) {
            return '---';
        }

        return number_format($this->calificacion, 2, '.', '');
    }

    /**
     * Obtener calificación como string con descripción
     */
    public function obtenerCalificacionDescriptiva(): string
    {
        if ($this->estaPendiente()) {
            return 'Pendiente';
        }

        if ($this->calificacion >= 4.5) {
            return 'Excelente';
        } elseif ($this->calificacion >= 3.8) {
            return 'Muy Bien';
        } elseif ($this->calificacion >= 3.0) {
            return 'Bien';
        } else {
            return 'Insuficiente';
        }
    }
}

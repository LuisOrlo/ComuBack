<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CambioHorario extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'academic.cambios_horario';
    protected $connection = 'pgsql';
    public $timestamps = true;

    protected $fillable = [
        'matricula_origen_id',
        'curso_abierto_antiguo_id',
        'curso_abierto_nuevo_id',
        'motivo',
        'estado',
        'observaciones_admin',
    ];

    protected $casts = [
        'estado' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_APROBADO = 'aprobado';
    const ESTADO_RECHAZADO = 'rechazado';
    const ESTADO_COMPLETADO = 'completado';

    const ESTADOS_VALIDOS = [
        'pendiente',
        'aprobado',
        'rechazado',
        'completado',
    ];

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Matrícula que solicita el cambio
     */
    public function matriculaOrigen(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_origen_id', 'id');
    }

    /**
     * Curso abierto antiguo (de donde sale)
     */
    public function cursoAbiertoAntiguo(): BelongsTo
    {
        return $this->belongsTo(CursoAbierto::class, 'curso_abierto_antiguo_id', 'id');
    }

    /**
     * Curso abierto nuevo (a donde va)
     */
    public function cursoAbiertoNuevo(): BelongsTo
    {
        return $this->belongsTo(CursoAbierto::class, 'curso_abierto_nuevo_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo cambios pendientes
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    /**
     * Solo cambios aprobados
     */
    public function scopeAprobados($query)
    {
        return $query->where('estado', self::ESTADO_APROBADO);
    }

    /**
     * Solo cambios rechazados
     */
    public function scopeRechazados($query)
    {
        return $query->where('estado', self::ESTADO_RECHAZADO);
    }

    /**
     * Solo cambios completados
     */
    public function scopeCompletados($query)
    {
        return $query->where('estado', self::ESTADO_COMPLETADO);
    }

    /**
     * Por matrícula
     */
    public function scopeDeMatricula($query, $matriculaId)
    {
        return $query->where('matricula_origen_id', $matriculaId);
    }

    /**
     * Por curso antiguo
     */
    public function scopeDelCursoAntiguo($query, $cursoAbiertoId)
    {
        return $query->where('curso_abierto_antiguo_id', $cursoAbiertoId);
    }

    /**
     * Por curso nuevo
     */
    public function scopeDelCursoNuevo($query, $cursoAbiertoId)
    {
        return $query->where('curso_abierto_nuevo_id', $cursoAbiertoId);
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * ¿Está pendiente?
     */
    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    /**
     * ¿Está aprobada?
     */
    public function estaAprobada(): bool
    {
        return $this->estado === self::ESTADO_APROBADO;
    }

    /**
     * ¿Fue rechazada?
     */
    public function fueRechazada(): bool
    {
        return $this->estado === self::ESTADO_RECHAZADO;
    }

    /**
     * ¿Está completada?
     */
    public function estaCompletada(): bool
    {
        return $this->estado === self::ESTADO_COMPLETADO;
    }

    /**
     * ¿Puede ser aprobada? (Solo si está pendiente)
     */
    public function puedeSerAprobada(): bool
    {
        return $this->estaPendiente();
    }

    /**
     * ¿Puede ser rechazada? (Solo si está pendiente)
     */
    public function puedeSerRechazada(): bool
    {
        return $this->estaPendiente();
    }

    /**
     * ¿Puede ser completada? (Solo si está aprobada)
     */
    public function puedeSerCompletada(): bool
    {
        return $this->estaAprobada();
    }

    /**
     * Obtener descripción del estado
     */
    public function obtenerDescripcionEstado(): string
    {
        $descripciones = [
            'pendiente' => 'Pendiente de Aprobación',
            'aprobado' => 'Aprobado',
            'rechazado' => 'Rechazado',
            'completado' => 'Completado',
        ];

        return $descripciones[$this->estado] ?? 'Desconocido';
    }

    /**
     * ¿Es válido el cambio?
     */
    public function esValido(): bool
    {
        return !empty($this->matricula_origen_id)
            && !empty($this->curso_abierto_antiguo_id)
            && !empty($this->curso_abierto_nuevo_id)
            && $this->curso_abierto_antiguo_id !== $this->curso_abierto_nuevo_id
            && in_array($this->estado, self::ESTADOS_VALIDOS);
    }

    /**
     * Obtener resumen del cambio
     */
    public function obtenerResumen(): string
    {
        $cursoAntiguo = $this->cursoAbiertoAntiguo ? $this->cursoAbiertoAntiguo->nombre_instancia : 'Desconocido';
        $cursoNuevo = $this->cursoAbiertoNuevo ? $this->cursoAbiertoNuevo->nombre_instancia : 'Desconocido';
        
        return "{$cursoAntiguo} → {$cursoNuevo}";
    }
}

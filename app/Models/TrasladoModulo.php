<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrasladoModulo extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'academic.traslados_modulo';
    protected $connection = 'pgsql';
    public $timestamps = true;

    protected $fillable = [
        'matricula_origen_id',
        'modulo_antiguo_id',
        'modulo_nuevo_id',
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
     * Matrícula que solicita el traslado
     */
    public function matriculaOrigen(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_origen_id', 'id');
    }

    /**
     * Módulo antiguo (de donde sale)
     */
    public function moduloAntiguo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'modulo_antiguo_id', 'id');
    }

    /**
     * Módulo nuevo (a donde va)
     */
    public function moduloNuevo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'modulo_nuevo_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo traslados pendientes
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    /**
     * Solo traslados aprobados
     */
    public function scopeAprobados($query)
    {
        return $query->where('estado', self::ESTADO_APROBADO);
    }

    /**
     * Solo traslados rechazados
     */
    public function scopeRechazados($query)
    {
        return $query->where('estado', self::ESTADO_RECHAZADO);
    }

    /**
     * Solo traslados completados
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
     * Por módulo antiguo
     */
    public function scopeDelModuloAntiguo($query, $moduloId)
    {
        return $query->where('modulo_antiguo_id', $moduloId);
    }

    /**
     * Por módulo nuevo
     */
    public function scopeDelModuloNuevo($query, $moduloId)
    {
        return $query->where('modulo_nuevo_id', $moduloId);
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
     * ¿Está aprobado?
     */
    public function estaAprobado(): bool
    {
        return $this->estado === self::ESTADO_APROBADO;
    }

    /**
     * ¿Fue rechazado?
     */
    public function fueRechazado(): bool
    {
        return $this->estado === self::ESTADO_RECHAZADO;
    }

    /**
     * ¿Está completado?
     */
    public function estaCompletado(): bool
    {
        return $this->estado === self::ESTADO_COMPLETADO;
    }

    /**
     * ¿Puede ser aprobado?
     */
    public function puedeSerAprobado(): bool
    {
        return $this->estaPendiente();
    }

    /**
     * ¿Puede ser rechazado?
     */
    public function puedeSerRechazado(): bool
    {
        return $this->estaPendiente();
    }

    /**
     * ¿Puede ser completado?
     */
    public function puedeSerCompletado(): bool
    {
        return $this->estaAprobado();
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
     * ¿Es válido el traslado?
     */
    public function esValido(): bool
    {
        return !empty($this->matricula_origen_id)
            && !empty($this->modulo_antiguo_id)
            && !empty($this->modulo_nuevo_id)
            && $this->modulo_antiguo_id !== $this->modulo_nuevo_id
            && in_array($this->estado, self::ESTADOS_VALIDOS);
    }

    /**
     * Obtener resumen del traslado
     */
    public function obtenerResumen(): string
    {
        $moduloAntiguo = $this->moduloAntiguo ? $this->moduloAntiguo->nombre : 'Desconocido';
        $moduloNuevo = $this->moduloNuevo ? $this->moduloNuevo->nombre : 'Desconocido';
        
        return "{$moduloAntiguo} → {$moduloNuevo}";
    }
}

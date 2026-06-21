<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuentaPorCobrar extends Model
{
    use HasUuids;

    protected $table = 'finance.cuentas_por_cobrar';
    protected $connection = 'pgsql';
    public $timestamps = true;

    protected $fillable = [
        'matricula_id',
        'inscripcion_taller_id',
        'reserva_aula_id',
        'reserva_podcast_id',
        'servicio_streaming_id',
        'servicio_produccion_id',
        'edicion_video_id',
        'alquiler_equipo_id',
        'reserva_radio_id',
        'clase_extra_id',
        'asesoria_id',
        'solicitud_inscripcion_id',
        'monto_total',
        'monto_abonado',
        'estado',
    ];

    protected $casts = [
        'monto_total' => 'decimal:2',
        'monto_abonado' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = ['created_at', 'updated_at'];

    protected $appends = ['tipo'];

    // Estados (t_estado_pago enum)
    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_ABONADO = 'abonado';
    const ESTADO_PAGADO = 'pagado';
    const ESTADO_ANULADO = 'anulado';

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Matrícula asociada
     */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_id', 'id');
    }

    /**
     * Solicitud de inscripción asociada
     */
    public function solicitudInscripcion(): BelongsTo
    {
        return $this->belongsTo(SolicitudInscripcion::class, 'solicitud_inscripcion_id', 'id');
    }

    /**
     * Inscripción taller asociada
     */
    public function inscripcionTaller(): BelongsTo
    {
        return $this->belongsTo(InscripcionTaller::class, 'inscripcion_taller_id', 'id');
    }

    /**
     * Reserva de radio asociada
     */
    public function reservaRadio(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Services\ReservaRadio::class, 'reserva_radio_id', 'id');
    }

    /**
     * Transacciones de ingreso asociadas a esta cuenta
     */
    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionIngreso::class, 'cuenta_cobrar_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo cuentas pendientes
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    /**
     * Solo cuentas con abonos
     */
    public function scopeAbonadas($query)
    {
        return $query->where('estado', self::ESTADO_ABONADO);
    }

    /**
     * Solo cuentas pagadas
     */
    public function scopePagadas($query)
    {
        return $query->where('estado', self::ESTADO_PAGADO);
    }

    /**
     * Por solicitud de inscripción
     */
    public function scopeDeSolicitud($query, $solicitudId)
    {
        return $query->where('solicitud_inscripcion_id', $solicitudId);
    }

    /**
     * Por matrícula
     */
    public function scopeDeMatricula($query, $matriculaId)
    {
        return $query->where('matricula_id', $matriculaId);
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
     * ¿Está abonada?
     */
    public function estaAbonada(): bool
    {
        return $this->estado === self::ESTADO_ABONADO;
    }

    /**
     * ¿Está pagada?
     */
    public function estaPagada(): bool
    {
        return $this->estado === self::ESTADO_PAGADO;
    }

    /**
     * ¿Fue anulada?
     */
    public function fueAnulada(): bool
    {
        return $this->estado === self::ESTADO_ANULADO;
    }

    /**
     * Obtener saldo pendiente
     */
    public function obtenerSaldoPendiente()
    {
        return $this->monto_total - $this->monto_abonado;
    }

    /**
     * ¿Está completamente pagada?
     */
    public function estaCompletamentePagada(): bool
    {
        return $this->obtenerSaldoPendiente() <= 0;
    }

    /**
     * Obtener porcentaje pagado
     */
    public function obtenerPorcentajePagado(): float
    {
        if ($this->monto_total <= 0) {
            return 0;
        }

        return round(($this->monto_abonado / $this->monto_total) * 100, 2);
    }

    /**
     * Obtener descripción del estado
     */
    public function obtenerDescripcionEstado(): string
    {
        $descripciones = [
            self::ESTADO_PENDIENTE => 'Pendiente',
            self::ESTADO_ABONADO => 'Abonado',
            self::ESTADO_PAGADO => 'Pagado',
            self::ESTADO_ANULADO => 'Anulado',
        ];

        return $descripciones[$this->estado] ?? 'Desconocido';
    }

    /**
     * Determinar el tipo de cuenta para clasificación en el frontend
     */
    public function getTipoAttribute(): string
    {
        if ($this->inscripcion_taller_id) {
            return 'taller';
        }

        if ($this->reserva_aula_id || $this->reserva_podcast_id || $this->alquiler_equipo_id || $this->reserva_radio_id) {
            return 'servicio';
        }

        return 'curso';
    }
}

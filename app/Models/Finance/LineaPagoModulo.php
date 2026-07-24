<?php

namespace App\Models\Finance;

use App\Models\Matricula;
use App\Models\Modulo;
use App\Models\Persona;
use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LineaPagoModulo extends Model
{
    use HasUuids;

    protected $table = 'finance.lineas_pago_modulo';
    protected $connection = 'pgsql';
    public $timestamps = true;

    protected $fillable = [
        'matricula_id',
        'modulo_id',
        'tipo',
        'monto_original',
        'monto_ajustado',
        'motivo_ajuste',
        'ajustado_por',
        'fecha_ajuste',
        'monto_abonado',
        'estado',
        'orden',
    ];

    protected $casts = [
        'monto_original' => 'decimal:2',
        'monto_ajustado' => 'decimal:2',
        'monto_abonado' => 'decimal:2',
        'fecha_ajuste' => 'datetime',
        'orden' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = ['created_at', 'updated_at'];

    protected $appends = ['saldo_pendiente', 'excedente'];

    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_ABONADO = 'abonado';
    const ESTADO_PAGADO = 'pagado';

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_id', 'id');
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'modulo_id', 'id');
    }

    public function ajustadoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'ajustado_por', 'id');
    }

    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionIngreso::class, 'linea_pago_modulo_id', 'id');
    }

    public function getSaldoPendienteAttribute(): float
    {
        return max(0, $this->monto_ajustado - $this->monto_abonado);
    }

    public function getExcedenteAttribute(): float
    {
        return max(0, $this->monto_abonado - $this->monto_ajustado);
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    public function scopeAbonadas($query)
    {
        return $query->where('estado', self::ESTADO_ABONADO);
    }

    public function scopePagadas($query)
    {
        return $query->where('estado', self::ESTADO_PAGADO);
    }

    public function scopeDeMatricula($query, $matriculaId)
    {
        return $query->where('matricula_id', $matriculaId);
    }

    public function scopeConExcedente($query)
    {
        return $query->whereRaw('monto_abonado > monto_ajustado');
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    public function estaAbonada(): bool
    {
        return $this->estado === self::ESTADO_ABONADO;
    }

    public function estaPagada(): bool
    {
        return $this->estado === self::ESTADO_PAGADO;
    }

    public function estaCompletamentePagada(): bool
    {
        return $this->monto_abonado >= $this->monto_ajustado;
    }

    /**
     * Aplica una transacción aprobada a esta línea de pago.
     * Incrementa monto_abonado y recalcula estado.
     */
    public function aplicarTransaccion(TransaccionIngreso $transaccion): void
    {
        if ($transaccion->linea_pago_modulo_id !== $this->id) {
            throw new \InvalidArgumentException('La transacción no pertenece a esta línea de pago');
        }
        if ($transaccion->estado_verificacion !== TransaccionIngreso::VERIFICACION_APROBADO) {
            throw new \InvalidArgumentException('Solo transacciones aprobadas pueden aplicarse');
        }

        $this->monto_abonado += $transaccion->monto;
        $this->recalcularEstado();
        $this->save();

        $this->syncCuentaPorCobrar();
    }

    /**
     * Sincroniza el estado de la CuentaPorCobrar padre
     * basado en los montos actuales de todas las líneas de pago de la matrícula.
     */
    private function syncCuentaPorCobrar(): void
    {
        $matricula = $this->matricula()->first();
        if (! $matricula) return;

        $cuenta = $matricula->cuentaPorCobrar()->first();
        if (! $cuenta) return;

        $totalAbonado = $matricula->lineasPago()->sum('monto_abonado');
        $cuenta->update([
            'monto_abonado' => $totalAbonado,
            'estado' => $totalAbonado >= $cuenta->monto_total
                ? CuentaPorCobrar::ESTADO_PAGADO
                : ($totalAbonado > 0 ? CuentaPorCobrar::ESTADO_ABONADO : CuentaPorCobrar::ESTADO_PENDIENTE),
        ]);
    }

    /**
     * Revierte una transacción previamente aplicada.
     * Decrementa monto_abonado y recalcula estado.
     */
    public function revertirTransaccion(TransaccionIngreso $transaccion): void
    {
        if ($transaccion->linea_pago_modulo_id !== $this->id) {
            throw new \InvalidArgumentException('La transacción no pertenece a esta línea de pago');
        }

        $this->monto_abonado -= $transaccion->monto;
        $this->recalcularEstado();
        $this->save();
    }

    public function recalcularEstado(): void
    {
        $this->estado = match(true) {
            $this->monto_abonado >= $this->monto_ajustado => self::ESTADO_PAGADO,
            $this->monto_abonado > 0 => self::ESTADO_ABONADO,
            default => self::ESTADO_PENDIENTE,
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaccionIngreso extends Model
{
    use HasUuids;

    protected $table = 'finance.transacciones_ingreso';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'cuenta_cobrar_id',
        'monto',
        'metodo_pago',
        'comprobante_url',
        'fecha_pago',
        'registrado_por',
        'observaciones',
        'estado_verificacion',
        'verificado_por',
        'fecha_verificacion',
        'motivo_rechazo'
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha_pago' => 'datetime',
        'fecha_verificacion' => 'datetime',
    ];

    // Estados de verificación
    const VERIFICACION_PENDIENTE = 'pendiente';
    const VERIFICACION_APROBADO = 'aprobado';
    const VERIFICACION_RECHAZADO = 'rechazado';

    public function cuentaPorCobrar(): BelongsTo
    {
        return $this->belongsTo(CuentaPorCobrar::class, 'cuenta_cobrar_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'registrado_por');
    }

    public function verificador(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'verificado_por');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CursoInteresado extends Model
{
    use HasUuids;

    protected $table = 'curso_interesados';

    protected $fillable = [
        'curso_abierto_id',
        'taller_id',
        'nombres',
        'apellidos',
        'telefono',
        'cedula',
        'correo',
        'monto_pago_anticipo',
        'comprobante_url',
        'descuento',
        'motivo_descuento',
        'precio_final',
        'pagos',
        'observaciones',
        'creado_por_id',
    ];

    protected $casts = [
        'monto_pago_anticipo' => 'float',
        'descuento' => 'float',
        'precio_final' => 'float',
        'pagos' => 'array',
    ];

    public function cursoAbierto(): BelongsTo
    {
        return $this->belongsTo(CursoAbierto::class, 'curso_abierto_id');
    }

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(CuentaSistema::class, 'creado_por_id');
    }
}

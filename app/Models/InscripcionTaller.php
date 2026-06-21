<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InscripcionTaller extends Model
{
    use HasUuids;

    protected $connection = 'pgsql';
    protected $table = 'academic.inscripciones_taller';

    protected $fillable = [
        'taller_id',
        'nombres',
        'apellidos',
        'cedula',
        'correo',
        'telefono',
        'ciudad',
        'ocupacion',
        'direccion',
        'estado_civil',
        'fecha_nacimiento',
        'edad',
        'fecha_inscripcion',
        'estado',
        'tipo_pago',
        'monto_pagado',
        'metodo_pago',
        'comprobante_url',
        'cedula_url',
        'pago_verificado',
        'fecha_pago',
    ];

    public $timestamps = false;

    protected $casts = [
        'fecha_inscripcion' => 'date',
        'fecha_pago' => 'date',
        'fecha_nacimiento' => 'date',
        'monto_pagado' => 'decimal:2',
        'pago_verificado' => 'boolean',
        'edad' => 'integer',
    ];

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }
}

<?php

namespace App\Models\Services;

use App\Models\ClienteExterno;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservaRadio extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'services.reservas_radio';

    protected static function booted(): void
    {
        static::created(function ($model) {
            if ($model->cliente_externo_id) {
                ClienteExterno::where('id', $model->cliente_externo_id)
                    ->update(['es_cliente' => true]);
            }
        });
    }

    protected $fillable = [
        'tarifa_id',
        'persona_id',
        'cliente_externo_id',
        'fecha_reserva',
        'hora_inicio',
        'hora_fin',
        'incluye_operador',
        'operador_id',
        'precio_total',
        'precio_original',
        'monto_descuento',
        'motivo_descuento',
        'observaciones',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'precio_total' => 'decimal:2',
            'precio_original' => 'decimal:2',
            'monto_descuento' => 'decimal:2',
            'fecha_reserva' => 'date:Y-m-d',
            'incluye_operador' => 'boolean',
            'hora_inicio' => 'string',
            'hora_fin' => 'string',
        ];
    }

    public function tarifa(): BelongsTo
    {
        return $this->belongsTo(TarifaRadio::class, 'tarifa_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function clienteExterno(): BelongsTo
    {
        return $this->belongsTo(ClienteExterno::class, 'cliente_externo_id');
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'operador_id');
    }

    public function asignacionesPersonal(): HasMany
    {
        return $this->hasMany(AsignacionPersonal::class, 'reserva_radio_id');
    }

    public function cuentaPorCobrar(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\CuentaPorCobrar::class, 'reserva_radio_id');
    }
}

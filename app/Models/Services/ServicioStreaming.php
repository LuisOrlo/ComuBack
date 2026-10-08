<?php

namespace App\Models\Services;

use App\Models\Ciudad;
use App\Models\ClienteExterno;
use App\Models\CuentaPorCobrar;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServicioStreaming extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'pgsql';
    protected $table = 'services.servicios_streaming';

    public $timestamps = true;

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
        'persona_id',
        'cliente_externo_id',
        'ciudad_id',
        'titulo',
        'fecha_evento',
        'hora_inicio',
        'hora_fin',
        'lugar',
        'direccion',
        'referencias_ubicacion',
        'equipos_detalle',
        'descripcion',
        'observaciones',
        'precio_original',
        'monto_descuento',
        'motivo_descuento',
        'monto_recargo',
        'motivo_recargo',
        'precio_total',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_evento' => 'date:Y-m-d',
            'precio_original' => 'decimal:2',
            'monto_descuento' => 'decimal:2',
            'monto_recargo' => 'decimal:2',
            'precio_total' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Responsable persona natural (si aplica)
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    /**
     * Responsable cliente externo o corporativo (si aplica)
     */
    public function clienteExterno(): BelongsTo
    {
        return $this->belongsTo(ClienteExterno::class, 'cliente_externo_id');
    }

    /**
     * Ciudad donde se ejecuta la cobertura externa
     */
    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class, 'ciudad_id');
    }

    /**
     * Personal técnico asignado al evento
     */
    public function asignacionesPersonal(): HasMany
    {
        return $this->hasMany(AsignacionPersonal::class, 'servicio_streaming_id');
    }

    /**
     * Cuenta por cobrar vinculada
     */
    public function cuentaPorCobrar(): HasOne
    {
        return $this->hasOne(CuentaPorCobrar::class, 'servicio_streaming_id');
    }
}

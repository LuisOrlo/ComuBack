<?php

namespace App\Models\Services;

use App\Models\ClienteExterno;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlquilerEquipo extends Model
{
    use HasUuids;

    protected $connection = 'pgsql';
    protected $table = 'services.alquiler_equipos';

    protected $fillable = [
        'equipo_id',
        'persona_id',
        'cliente_externo_id',
        'fecha_entrega',
        'fecha_devolucion_esperada',
        'fecha_recepcion',
        'foto_salida_url',
        'foto_retorno_url',
        'observaciones',
        'precio_total',
        'estado',
    ];

    protected $casts = [
        'fecha_entrega' => 'datetime',
        'fecha_devolucion_esperada' => 'datetime',
        'fecha_recepcion' => 'datetime',
        'precio_total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function clienteExterno(): BelongsTo
    {
        return $this->belongsTo(ClienteExterno::class, 'cliente_externo_id');
    }

    public static function actualizarVencidos(): int
    {
        return self::whereIn('estado', ['activo', 'entregado'])
            ->where('fecha_devolucion_esperada', '<', now())
            ->update(['estado' => 'vencido']);
    }
}

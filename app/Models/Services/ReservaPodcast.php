<?php

namespace App\Models\Services;

use App\Models\ClienteExterno;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservaPodcast extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'services.reservas_podcast';

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
        'paquete_id',
        'fecha_reserva',
        'hora_inicio',
        'hora_fin',
        'precio_total',
        'observaciones',
        'estado',
        'titulo',
    ];

    protected function casts(): array
    {
        return [
            'precio_total' => 'decimal:2',
            'fecha_reserva' => 'date:Y-m-d',
        ];
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(PaquetePodcast::class, 'paquete_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function clienteExterno(): BelongsTo
    {
        return $this->belongsTo(ClienteExterno::class, 'cliente_externo_id');
    }

    public function asignacionesPersonal(): HasMany
    {
        return $this->hasMany(AsignacionPersonal::class, 'reserva_podcast_id');
    }
}

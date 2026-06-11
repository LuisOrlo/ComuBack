<?php

namespace App\Models\Services;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrabajoEdicion extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'services.trabajos_edicion';

    protected $fillable = [
        'titulo',
        'descripcion',
        'fecha_recibo',
        'fecha_limite',
        'fecha_entrega',
        'nivel',
        'estado',
        'editor_ids',
        'reserva_podcast_id',
        'precio_cobrado',
        'cobro_registrado',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'editor_ids' => 'array',
            'cobro_registrado' => 'boolean',
            'precio_cobrado' => 'decimal:2',
            'fecha_recibo' => 'date:Y-m-d',
            'fecha_limite' => 'date:Y-m-d',
            'fecha_entrega' => 'date:Y-m-d',
        ];
    }

    public function editores()
    {
        return Persona::whereIn('id', $this->editor_ids ?? [])->get();
    }

    public function reservaPodcast(): BelongsTo
    {
        return $this->belongsTo(ReservaPodcast::class, 'reserva_podcast_id');
    }
}

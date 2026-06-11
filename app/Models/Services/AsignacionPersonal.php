<?php

namespace App\Models\Services;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionPersonal extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'services.asignaciones_personal';

    protected $fillable = [
        'persona_id',
        'reserva_podcast_id',
        'rol_en_servicio',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function reservaPodcast(): BelongsTo
    {
        return $this->belongsTo(ReservaPodcast::class, 'reserva_podcast_id');
    }
}

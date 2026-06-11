<?php

namespace App\Models\Services;

use App\Models\ClienteExterno;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservaAula extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'services.reservas_aulas';

    public $timestamps = false;

    protected $fillable = [
        'aula_id',
        'persona_id',
        'cliente_externo_id',
        'fecha_reserva',
        'hora_inicio',
        'hora_fin',
        'precio_total',
        'estado'
    ];

    public function aula(): BelongsTo
    {
        return $this->belongsTo(Aula::class, 'aula_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function clienteExterno(): BelongsTo
    {
        return $this->belongsTo(ClienteExterno::class, 'cliente_externo_id');
    }
}

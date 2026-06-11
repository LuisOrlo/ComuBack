<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroAsistenciaStaff extends Model
{
    use HasUuids;

    protected $table = 'ops.registro_asistencia_staff';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'persona_id',
        'fecha',
        'hora_entrada',
        'hora_salida',
        'actividades',
        'observaciones',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id', 'id');
    }
}

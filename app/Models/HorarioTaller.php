<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorarioTaller extends Model
{
    use HasUuids, SoftDeletes;

    protected $connection = 'pgsql';
    protected $table = 'academic.horarios_talleres';

    protected $fillable = [
        'taller_id',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'aula',
        'capacidad',
    ];

    protected $casts = [
        'dia_semana' => 'integer',
        'capacidad' => 'integer',
    ];

    // Relations
    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    // Utility Methods
    public function duracionMinutos(): int
    {
        $inicio = \Carbon\Carbon::createFromFormat('H:i:s', $this->hora_inicio);
        $fin = \Carbon\Carbon::createFromFormat('H:i:s', $this->hora_fin);
        return $inicio->diffInMinutes($fin);
    }

    public function nombreDia(): string
    {
        $dias = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        return $dias[$this->dia_semana] ?? 'Desconocido';
    }
}

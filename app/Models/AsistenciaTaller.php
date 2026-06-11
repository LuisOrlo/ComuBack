<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsistenciaTaller extends Model
{
    use HasUuids, SoftDeletes;

    protected $connection = 'pgsql';
    protected $table = 'academic.asistencias_talleres';

    protected $fillable = [
        'taller_id',
        'fecha_sesion',
        'asistentes',
        'capacidad_registrada',
        'observaciones',
    ];

    protected $casts = [
        'fecha_sesion' => 'date',
        'asistentes' => 'integer',
        'capacidad_registrada' => 'integer',
    ];

    // Relations
    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    // Utility Methods
    public function tasaAsistencia(): float
    {
        return $this->capacidad_registrada > 0 
            ? ($this->asistentes / $this->capacidad_registrada) * 100 
            : 0;
    }

    public function ausentes(): int
    {
        return $this->capacidad_registrada - $this->asistentes;
    }
}

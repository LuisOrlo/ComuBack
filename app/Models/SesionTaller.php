<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SesionTaller extends Model
{
    use HasUuids, SoftDeletes;

    protected $connection = 'pgsql';
    protected $table = 'academic.sesiones_talleres';

    protected $fillable = [
        'taller_id',
        'fecha_sesion',
        'hora_inicio',
        'hora_fin',
        'aula',
        'tema',
        'observaciones',
    ];

    protected $casts = [
        'fecha_sesion' => 'date',
    ];

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }
}

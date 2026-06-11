<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clase extends Model
{
    use HasUuids;

    protected $table = 'academic.clases';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'modulo_id',
        'instructor_id',
        'fecha_clase',
        'hora_inicio',
        'hora_fin',
        'observaciones',
    ];

    protected $casts = [
        'fecha_clase' => 'date',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'instructor_id');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }
}

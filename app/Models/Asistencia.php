<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asistencia extends Model
{
    use HasUuids;

    protected $table = 'academic.asistencias';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'matricula_id',
        'clase_id',
        'asistio',
        'estado',
        'observaciones',
    ];

    protected $casts = [
        'asistio' => 'boolean',
    ];

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function clase(): BelongsTo
    {
        return $this->belongsTo(Clase::class);
    }
}

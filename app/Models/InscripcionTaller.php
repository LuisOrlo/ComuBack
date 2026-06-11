<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InscripcionTaller extends Model
{
    use HasUuids, SoftDeletes;

    protected $connection = 'pgsql';
    protected $table = 'academic.inscripciones_talleres';

    protected $fillable = [
        'taller_id',
        'estudiante_id',
        'fecha_inscripcion',
        'estado',
    ];

    protected $casts = [
        'fecha_inscripcion' => 'date',
    ];

    // Relations
    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class, 'taller_id');
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'estudiante_id');
    }

    // Scopes
    public function scopeActivos($query)
    {
        return $query->where('estado', 'inscrito');
    }

    public function scopeCompletados($query)
    {
        return $query->where('estado', 'completado');
    }

    // Utility Methods
    public function completoTaller(): bool
    {
        return $this->estado === 'completado';
    }

    public function retirado(): bool
    {
        return $this->estado === 'retirado';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParticipanteExterno extends Model
{
    use HasUuids, SoftDeletes;

    protected $connection = 'pgsql';
    protected $table = 'academic.participantes_externos';

    protected $fillable = [
        'nombre',
        'email',
        'telefono',
        'institucion',
        'tipo',
    ];

    // Relations
    public function inscripciones(): HasMany
    {
        return $this->hasMany(InscripcionExternoTaller::class, 'participante_externo_id');
    }

    // Scopes
    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopePorInstitucion($query, $institucion)
    {
        return $query->where('institucion', $institucion);
    }

    // Utility Methods
    public function totalTalleres(): int
    {
        return $this->inscripciones()->count();
    }

    public function talleresCompletados(): int
    {
        return $this->inscripciones()->where('estado', 'completado')->count();
    }
}

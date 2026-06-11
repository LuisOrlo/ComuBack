<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Taller extends Model
{
    use HasUuids, SoftDeletes;

    protected $connection = 'pgsql';
    protected $table = 'academic.talleres';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'categoria',
        'fecha_inicio',
        'fecha_fin',
        'capacidad',
        'profesor_id',
        'estado',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'capacidad' => 'integer',
    ];

    // Relations
    public function profesor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profesor_id');
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioTaller::class, 'taller_id');
    }

    public function inscripciones(): HasMany
    {
        return $this->hasMany(InscripcionTaller::class, 'taller_id');
    }

    public function inscripciones_externos(): HasMany
    {
        return $this->hasMany(InscripcionExternoTaller::class, 'taller_id');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(AsistenciaTaller::class, 'taller_id');
    }

    // Scopes
    public function scopeActivos($query)
    {
        return $query->whereIn('estado', ['planificado', 'activo']);
    }

    public function scopePorProfesor($query, $profesor_id)
    {
        return $query->where('profesor_id', $profesor_id);
    }

    public function scopePorCategoria($query, $categoria)
    {
        return $query->where('categoria', $categoria);
    }

    public function scopeFechasEntre($query, $fecha_inicio, $fecha_fin)
    {
        return $query->whereBetween('fecha_inicio', [$fecha_inicio, $fecha_fin]);
    }

    // Utility Methods
    public function totalInscripciones(): int
    {
        return $this->inscripciones()->count() + $this->inscripciones_externos()->count();
    }

    public function capacidadDisponible(): int
    {
        return $this->capacidad - $this->totalInscripciones();
    }

    public function estaActivo(): bool
    {
        return $this->estado === 'activo';
    }

    public function permitirInscripcion(): bool
    {
        return $this->fecha_inicio > now()->toDateString() && 
               $this->estado !== 'cancelado' && 
               $this->capacidadDisponible() > 0;
    }

    public function tasaAsistencia(): float
    {
        $sesiones = $this->asistencias()->count();
        if ($sesiones === 0) return 0;

        $totalAsistencias = $this->asistencias()->sum('asistentes');
        $totalCapacidad = $this->asistencias()->sum('capacidad_registrada');

        return $totalCapacidad > 0 ? ($totalAsistencias / $totalCapacidad) * 100 : 0;
    }
}

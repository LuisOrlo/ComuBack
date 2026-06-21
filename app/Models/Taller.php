<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Taller extends Model
{
    use HasUuids;

    protected $connection = 'pgsql';
    protected $table = 'academic.talleres';

    protected $fillable = [
        'nombre',
        'descripcion',
        'fecha',
        'fecha_fin',
        'hora_inicio',
        'hora_fin',
        'instructor_id',
        'modalidad',
        'capacidad_maxima',
        'precio',
        'estado',
    ];

    public $timestamps = false;

    protected $casts = [
        'fecha' => 'date',
        'fecha_fin' => 'date',
        'precio' => 'decimal:2',
        'capacidad_maxima' => 'integer',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'instructor_id');
    }

    public function inscripciones(): HasMany
    {
        return $this->hasMany(InscripcionTaller::class, 'taller_id');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(AsistenciaTaller::class, 'taller_id');
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioTaller::class, 'taller_id');
    }

    public function esMultiDia(): bool
    {
        return $this->fecha_fin !== null && $this->fecha_fin->greaterThan($this->fecha);
    }

    public function scopeActivos($query)
    {
        return $query->whereIn('estado', ['pendiente', 'confirmado']);
    }

    public function scopePorInstructor($query, $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeProximos($query)
    {
        return $query->where(function ($q) {
            $q->where('fecha', '>=', now()->toDateString())
              ->orWhere('fecha_fin', '>=', now()->toDateString());
        })->whereIn('estado', ['pendiente', 'confirmado']);
    }

    public function scopePasados($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($inner) {
                $inner->where('fecha', '<', now()->toDateString())
                      ->whereNull('fecha_fin');
            })->orWhere(function ($inner) {
                $inner->whereNotNull('fecha_fin')
                      ->where('fecha_fin', '<', now()->toDateString());
            });
        });
    }

    public function totalInscripciones(): int
    {
        return $this->inscripciones()->where('estado', 'activo')->count();
    }

    public function capacidadDisponible(): int
    {
        return $this->capacidad_maxima - $this->totalInscripciones();
    }

    public function permitirInscripcion(): bool
    {
        $fechaFin = $this->fecha_fin ?? $this->fecha;
        return $this->fecha && $fechaFin->isFuture()
            && $this->estado !== 'cancelado'
            && $this->capacidadDisponible() > 0;
    }

    public function tasaOcupacion(): float
    {
        if (!$this->capacidad_maxima) return 0;
        return ($this->totalInscripciones() / $this->capacidad_maxima) * 100;
    }
}

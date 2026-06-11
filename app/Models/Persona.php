<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Persona extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'people.personas';

    protected $fillable = [
        'tipo',
        'cedula',
        'nombres',
        'apellidos',
        'correo',
        'celular',
        'ciudad_id',
        'cedula_photo_url',
        'ficha_registro_url',
        'es_activo',
    ];

    protected function casts(): array
    {
        return [
            'es_activo' => 'boolean',
        ];
    }

    public function cuentaSistema(): HasOne
    {
        return $this->hasOne(CuentaSistema::class);
    }

    public function perfilEstudiante(): HasOne
    {
        return $this->hasOne(PerfilEstudiante::class);
    }

    public function perfilInstructor(): HasOne
    {
        return $this->hasOne(PerfilInstructor::class);
    }

    public function perfilStaff(): HasOne
    {
        return $this->hasOne(PerfilStaff::class);
    }

    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class, 'estudiante_id', 'id');
    }

    public function scopeEstudiantes($query)
    {
        return $query->where('tipo', 'estudiante');
    }

    public function scopeInstructores($query)
    {
        return $query->where('tipo', 'instructor');
    }

    public function scopeStaff($query)
    {
        return $query->whereIn('tipo', ['staff', 'secretaria', 'admin']);
    }

    public function scopeActivos($query)
    {
        return $query->where('es_activo', true);
    }

    public function scopeBuscar($query, $termino)
    {
        return $query->where(function ($q) use ($termino) {
            $q->where('nombres', 'ilike', "%{$termino}%")
              ->orWhere('apellidos', 'ilike', "%{$termino}%")
              ->orWhere('cedula', 'ilike', "%{$termino}%")
              ->orWhere('correo', 'ilike', "%{$termino}%");
        });
    }
}

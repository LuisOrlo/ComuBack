<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerfilEstudiante extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'people.perfil_estudiante';

    protected $fillable = [
        'persona_id',
        'notas_internas',
        'genero',
        'ocupacion',
        'direccion',
        'estado_civil',
        'edad',
        'ciudad',
        'nivel_educativo',
        'terminos_aceptados',
        'fecha_terminos_aceptados',
    ];

    protected function casts(): array
    {
        return [
            'primera_matricula' => 'date',
            'ultima_matricula' => 'date',
            'total_cursos' => 'integer',
            'terminos_aceptados' => 'boolean',
            'fecha_terminos_aceptados' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}

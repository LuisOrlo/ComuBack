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
        'fecha_nacimiento',
        'notas_internas',
        'primera_matricula',
        'ultima_matricula',
        'total_cursos',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'primera_matricula' => 'date',
            'ultima_matricula' => 'date',
            'total_cursos' => 'integer',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}

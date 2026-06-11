<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerfilStaff extends Model
{
    use HasUuids;

    protected $table = 'people.perfil_staff';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'persona_id',
        'cargo',
        'salario_base',
        'fecha_ingreso',
        'es_pasante',
    ];

    protected function casts(): array
    {
        return [
            'es_pasante' => 'boolean',
            'salario_base' => 'decimal:2',
            'fecha_ingreso' => 'date',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id', 'id');
    }
}

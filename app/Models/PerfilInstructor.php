<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerfilInstructor extends Model
{
    use HasUuids;

    protected $table = 'people.perfil_instructor';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'persona_id',
        'especialidad',
        'bio',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id', 'id');
    }
}

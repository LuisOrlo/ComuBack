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
        'hoja_vida_path',
        'hoja_vida_nombre_original',
        'hoja_vida_mime',
        'hoja_vida_size',
        'hoja_vida_updated_at',
    ];

    protected $casts = [
        'hoja_vida_size' => 'integer',
        'hoja_vida_updated_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id', 'id');
    }
}

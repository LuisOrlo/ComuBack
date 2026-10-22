<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ciudad extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'core.ciudades';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
    ];

    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class);
    }

    public function cursosAbiertos(): HasMany
    {
        return $this->hasMany(CursoAbierto::class, 'ciudad_id', 'id');
    }

    public function talleres(): HasMany
    {
        return $this->hasMany(Taller::class, 'ciudad_id', 'id');
    }
}

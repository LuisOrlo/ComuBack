<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ciudad extends Model
{
    use HasFactory;

    protected $table = 'core.ciudades';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
    ];

    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class);
    }
}

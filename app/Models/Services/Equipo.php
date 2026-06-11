<?php

namespace App\Models\Services;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipo extends Model
{
    use HasUuids;

    protected $connection = 'pgsql';
    protected $table = 'services.equipos';

    protected $fillable = [
        'nombre',
        'descripcion',
        'foto_url',
        'precio_diario',
        'estado',
    ];

    protected $casts = [
        'precio_diario' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function alquileres(): HasMany
    {
        return $this->hasMany(AlquilerEquipo::class, 'equipo_id');
    }
}

<?php

namespace App\Models\Services;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Set extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'services.sets';

    protected $fillable = [
        'nombre',
        'precio_hora',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_hora' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaSet::class, 'set_id');
    }
}

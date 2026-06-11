<?php

namespace App\Models\Services;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaquetePodcast extends Model
{
    use HasFactory;

    protected $table = 'services.paquetes_podcast';

    public $timestamps = false;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio_base',
        'es_activo',
    ];

    protected function casts(): array
    {
        return [
            'es_activo' => 'boolean',
            'precio_base' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItemPaquetePodcast::class, 'paquete_id');
    }
}

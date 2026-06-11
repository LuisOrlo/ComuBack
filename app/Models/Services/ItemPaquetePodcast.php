<?php

namespace App\Models\Services;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemPaquetePodcast extends Model
{
    use HasFactory;

    protected $table = 'services.items_paquete_podcast';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'paquete_id',
        'descripcion',
    ];

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(PaquetePodcast::class, 'paquete_id');
    }
}

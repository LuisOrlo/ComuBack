<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EstudianteSegmento extends Model
{
    use HasUuids;

    protected $table = 'estudiante_segmentos';
    protected $connection = 'pgsql';
    public $timestamps = true;

    protected $fillable = [
        'nombre',
        'descripcion',
        'criterios',
    ];

    protected $casts = [
        'criterios' => 'array',
    ];
}

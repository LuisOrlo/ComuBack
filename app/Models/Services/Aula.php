<?php

namespace App\Models\Services;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Aula extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'services.aulas';

    // The table does not have created_at and updated_at
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'capacidad',
        'precio_hora',
        'caracteristicas'
    ];
}

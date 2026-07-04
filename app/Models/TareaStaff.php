<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TareaStaff extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'ops.tareas_staff';
    protected $connection = 'pgsql';

    protected $fillable = [
        'titulo',
        'descripcion',
        'persona_id',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'created_by',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'created_by' => 'integer',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id', 'id');
    }
}

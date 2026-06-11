<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorasInstructor extends Model
{
    use HasUuids;

    protected $table = 'finance.horas_instructor';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'instructor_id',
        'clase_id',
        'curso_abierto_id',
        'fecha',
        'horas_trabajadas',
        'tarifa_aplicada',
        'pagado',
        'egreso_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'horas_trabajadas' => 'decimal:2',
            'tarifa_aplicada' => 'decimal:2',
            'monto_a_pagar' => 'decimal:2',
            'pagado' => 'boolean',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'instructor_id', 'id');
    }
}

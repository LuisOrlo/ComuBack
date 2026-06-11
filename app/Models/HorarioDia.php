<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorarioDia extends Model
{
    protected $table = 'academic.horarios_dias';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'horario_id',
        'dia_semana',
    ];

    protected $casts = [
        'dia_semana' => 'integer',
    ];

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Horario al que pertenece este día
     */
    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'horario_id', 'id');
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * Obtener nombre del día (ej: "Lunes", "Martes")
     */
    public function obtenerNombreDia(): string
    {
        $dias = [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo',
        ];

        return $dias[$this->dia_semana] ?? 'Desconocido';
    }

    /**
     * Validar que el día sea válido (1-7)
     */
    public function esValido(): bool
    {
        return $this->dia_semana >= 1 && $this->dia_semana <= 7;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Horario extends Model
{
    use HasUuids;

    protected $table = 'academic.horarios';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'curso_abierto_id',
        'nombre_referencial',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'es_activo',
    ];

    protected $casts = [
        'es_activo' => 'boolean',
    ];

    /**
     * Accesor para dia_semana: decodifica el array nativo de PostgreSQL ({1,2,3})
     * que el cast 'array' de Laravel no maneja (espera JSON).
     */
    protected function diaSemana(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') return null;
                if (is_array($value)) return $value;
                $trimmed = trim((string) $value, '{}');
                if ($trimmed === '') return [];
                return array_map('intval', explode(',', $trimmed));
            },
        );
    }

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Curso abierto al que pertenece este horario
     */
    public function cursoAbierto(): BelongsTo
    {
        return $this->belongsTo(CursoAbierto::class, 'curso_abierto_id', 'id');
    }

    /**
     * Días de la semana en que se dicta este horario
     */
    public function diasSemana(): HasMany
    {
        return $this->hasMany(HorarioDia::class, 'horario_id', 'id');
    }

    /**
     * Matrículas asignadas a este horario
     */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class, 'horario_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo horarios activos
     */
    public function scopeActivos($query)
    {
        return $query->where('es_activo', true);
    }

    /**
     * Horarios de un curso específico
     */
    public function scopeDelCurso($query, $cursoAbiertoId)
    {
        return $query->where('curso_abierto_id', $cursoAbiertoId);
    }

    /**
     * Buscar por nombre referencial
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where('nombre_referencial', 'ilike', "%{$termino}%");
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * Obtener días de la semana como array [1, 2, 3] (Lunes, Martes, Miércoles)
     */
    public function obtenerDiasSemana(): array
    {
        return $this->diasSemana()
                    ->pluck('dia_semana')
                    ->sort()
                    ->values()
                    ->toArray();
    }

    /**
     * Obtener días de la semana como strings ['Lunes', 'Martes', ...]
     */
    public function obtenerDiasNombres(): array
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

        $diasNumero = $this->obtenerDiasSemana();
        
        return array_map(fn($dia) => $dias[$dia] ?? 'Desconocido', $diasNumero);
    }

    /**
     * Obtener descripción del horario (ej: "Lunes-Miércoles 8:00-10:00")
     */
    public function obtenerDescripcion(): string
    {
        $dias = implode('-', $this->obtenerDiasNombres());
        
        return "{$dias} {$this->hora_inicio}-{$this->hora_fin}";
    }

    /**
     * ¿Hay conflicto con otro horario?
     */
    public function tieneConflictoHorario(Horario $otro): bool
    {
        // Si los tiempos no solapan, no hay conflicto
        if ($this->hora_fin <= $otro->hora_inicio || $otro->hora_fin <= $this->hora_inicio) {
            return false;
        }

        // Si los tiempos solapan, verificar si tienen días en común
        $diasEste = collect($this->obtenerDiasSemana());
        $diasOtro = collect($otro->obtenerDiasSemana());

        return $diasEste->intersect($diasOtro)->count() > 0;
    }

    /**
     * ¿Es válido?
     */
    public function esValido(): bool
    {
        return !empty($this->nombre_referencial)
            && !empty($this->hora_inicio)
            && !empty($this->hora_fin)
            && $this->hora_inicio < $this->hora_fin
            && $this->diasSemana()->count() > 0;
    }

    /**
     * Obtener cantidad de matrículas en este horario
     */
    public function obtenerCountMatriculas(): int
    {
        return $this->matriculas()
                    ->whereIn('estado', ['activo', 'completado'])
                    ->whereNull('deleted_at')
                    ->count();
    }
}

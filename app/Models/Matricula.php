<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Matricula extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'academic.matriculas';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'estudiante_id',
        'curso_abierto_id',
        'horario_id',
        'precio_total',
        'tipo_pago',
        'voucher_url',
        'solicitud_inscripcion_id',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'calificacion_final',
        'observaciones',
        'solicitud_inscripcion_id',
        'fecha_inscripcion',
    ];

    protected $casts = [
        'estado' => 'string',
        'calificacion_final' => 'float',
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
        'fecha_inscripcion' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    const ESTADO_ACTIVO = 'activo';
    const ESTADO_COMPLETADO = 'completado';
    const ESTADO_RETIRADO = 'retirado';
    const ESTADO_REPROBADO = 'reprobado';

    const ESTADOS_VALIDOS = [
        'activo',
        'completado',
        'retirado',
        'reprobado',
    ];

    /**
     * Solicitud de inscripción relacionada
     */
    public function solicitudInscripcion(): BelongsTo
    {
        return $this->belongsTo(SolicitudInscripcion::class, 'solicitud_inscripcion_id', 'id');
    }

    /**
     * Estudiante matriculado
     */
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'estudiante_id', 'id');
    }

    /**
     * Curso en el que está matriculado
     */
    public function cursoAbierto(): BelongsTo
    {
        return $this->belongsTo(CursoAbierto::class, 'curso_abierto_id', 'id');
    }

    /**
     * Horario asignado
     */
    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'horario_id', 'id');
    }

    /**
     * Notas del estudiante en este curso
     */
    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class, 'matricula_id', 'id');
    }

    /**
     * Cambios de horario originados desde esta matrícula
     */
    public function cambiosHorario(): HasMany
    {
        return $this->hasMany(CambioHorario::class, 'matricula_origen_id', 'id');
    }

    /**
     * Traslados de módulos desde esta matrícula
     */
    public function trasladosModulo(): HasMany
    {
        return $this->hasMany(TrasladoModulo::class, 'matricula_origen_id', 'id');
    }

    /**
     * Cuenta por cobrar asociada a esta matrícula
     */
    public function cuentaPorCobrar(): HasOne
    {
        return $this->hasOne(CuentaPorCobrar::class, 'matricula_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo matrículas activas
     */
    public function scopeActivas($query)
    {
        return $query->where('estado', self::ESTADO_ACTIVO);
    }

    /**
     * Solo matrículas completadas
     */
    public function scopeCompletadas($query)
    {
        return $query->where('estado', self::ESTADO_COMPLETADO);
    }

    /**
     * Solo matrículas retiradas
     */
    public function scopeRetiradas($query)
    {
        return $query->where('estado', self::ESTADO_RETIRADO);
    }

    /**
     * Solo matrículas reprobadas
     */
    public function scopeReprobadas($query)
    {
        return $query->where('estado', self::ESTADO_REPROBADO);
    }

    /**
     * Matrículas activas o completadas
     */
    public function scopeEnCurso($query)
    {
        return $query->whereIn('estado', [self::ESTADO_ACTIVO, self::ESTADO_COMPLETADO]);
    }

    /**
     * Por estudiante
     */
    public function scopeDelEstudiante($query, $estudianteId)
    {
        return $query->where('estudiante_id', $estudianteId);
    }

    /**
     * Por curso
     */
    public function scopeDelCurso($query, $cursoAbiertoId)
    {
        return $query->where('curso_abierto_id', $cursoAbiertoId);
    }

    /**
     * Por horario
     */
    public function scopeDelHorario($query, $horarioId)
    {
        return $query->where('horario_id', $horarioId);
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * ¿Está activa?
     */
    public function estaActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    /**
     * ¿Está completada?
     */
    public function estaCompletada(): bool
    {
        return $this->estado === self::ESTADO_COMPLETADO;
    }

    /**
     * ¿Fue retirada?
     */
    public function fueRetirada(): bool
    {
        return $this->estado === self::ESTADO_RETIRADO;
    }

    /**
     * ¿Fue reprobada?
     */
    public function fueReprobada(): bool
    {
        return $this->estado === self::ESTADO_REPROBADO;
    }

    /**
     * ¿Está en vigencia?
     */
    public function estaEnVigencia(): bool
    {
        $ahora = Carbon::now();
        
        return $this->estaActiva()
            && $this->fecha_inicio <= $ahora
            && $this->fecha_fin >= $ahora;
    }

    /**
     * Obtener todas las notas del estudiante en este curso
     */
    public function obtenerNotas()
    {
        return $this->notas()
                    ->with('modulo')
                    ->orderBy('modulo_id')
                    ->get();
    }

    /**
     * Calcular promedio de notas (simple)
     */
    public function calcularPromedio(): ?float
    {
        $notas = $this->notas()
                      ->whereNotNull('calificacion')
                      ->pluck('calificacion');

        if ($notas->isEmpty()) {
            return null;
        }

        return round($notas->avg(), 2);
    }

    /**
     * Calcular promedio ponderado (con ponderación por módulo)
     */
    public function calcularPromedioPonderado(): ?float
    {
        $notas = $this->notas()
                      ->whereNotNull('calificacion')
                      ->with('modulo')
                      ->get();

        if ($notas->isEmpty()) {
            return null;
        }

        $sumaTotal = 0;
        $ponderacionTotal = 0;

        foreach ($notas as $nota) {
            if ($nota->modulo && $nota->calificacion) {
                $sumaTotal += $nota->calificacion * $nota->modulo->ponderacion;
                $ponderacionTotal += $nota->modulo->ponderacion;
            }
        }

        if ($ponderacionTotal == 0) {
            return null;
        }

        return round($sumaTotal / $ponderacionTotal, 2);
    }

    /**
     * ¿Tiene todas las notas registradas?
     */
    public function tieneTotalNotasRegistradas(): bool
    {
        $modulos = $this->cursoAbierto->modulos()->count();
        $notasRegistradas = $this->notas()
                                  ->whereNotNull('calificacion')
                                  ->count();

        return $modulos > 0 && $modulos === $notasRegistradas;
    }

    /**
     * ¿Es válida la matrícula?
     */
    public function esValida(): bool
    {
        return !empty($this->estudiante_id)
            && !empty($this->curso_abierto_id)
            && in_array($this->estado, self::ESTADOS_VALIDOS)
            && $this->fecha_inicio <= $this->fecha_fin;
    }

    /**
     * Obtener descripción del estado
     */
    public function obtenerDescripcionEstado(): string
    {
        $descripciones = [
            'activo' => 'Activo',
            'completado' => 'Completado',
            'retirado' => 'Retirado',
            'reprobado' => 'Reprobado',
        ];

        return $descripciones[$this->estado] ?? 'Desconocido';
    }
}

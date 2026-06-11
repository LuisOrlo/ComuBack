<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudInscripcion extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'academic.solicitudes_inscripcion';
    protected $connection = 'pgsql';
    public $timestamps = true;

    protected $fillable = [
        'persona_id',
        'participante_externo_id',
        'es_participante_externo',
        'curso_abierto_id',
        'monto_solicitado',
        'tipo_pago',
        'archivo_comprobante_url',
        'archivo_cedula_url',
        'tipo_comprobante',
        'fecha_pago_declarada',
        'estado',
        'validado_por',
        'motivo_rechazo',
        'observaciones_validacion',
        'fecha_validacion',
    ];

    protected $casts = [
        'es_participante_externo' => 'boolean',
        'monto_solicitado' => 'decimal:2',
        'fecha_pago_declarada' => 'date',
        'fecha_validacion' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = ['deleted_at'];

    // Estados
    const ESTADO_REGISTRADO = 'registrado';
    const ESTADO_PENDIENTE_VALIDACION = 'pendiente_validacion';
    const ESTADO_APROBADO = 'aprobado';
    const ESTADO_RECHAZADO = 'rechazado';
    const ESTADO_MATRICULA_CREADA = 'matricula_creada';
    const ESTADO_CANCELADO = 'cancelado';

    const ESTADOS_VALIDOS = [
        'registrado',
        'pendiente_validacion',
        'aprobado',
        'rechazado',
        'matricula_creada',
        'cancelado',
    ];

    const TIPOS_PAGO = ['completo', 'abono'];
    const TIPOS_COMPROBANTE = ['transferencia', 'deposito', 'efectivo', 'otro'];

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Estudiante con cuenta sistema
     */
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id', 'id');
    }

    /**
     * Participante externo (sin cuenta)
     */
    public function participanteExterno(): BelongsTo
    {
        return $this->belongsTo(ClienteExterno::class, 'participante_externo_id', 'id');
    }

    /**
     * Curso solicitado
     */
    public function cursoAbierto(): BelongsTo
    {
        return $this->belongsTo(CursoAbierto::class, 'curso_abierto_id', 'id');
    }

    /**
     * Personal que validó
     */
    public function validador(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'validado_por', 'id');
    }

    /**
     * Matrícula creada (después de aprobación)
     */
    public function matricula(): HasOne
    {
        return $this->hasOne(Matricula::class, 'solicitud_inscripcion_id', 'id');
    }

    /**
     * Cuentas por cobrar asociadas
     */
    public function cuentasPorCobrar(): HasMany
    {
        return $this->hasMany(CuentaPorCobrar::class, 'solicitud_inscripcion_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Solo solicitudes pendientes de validación
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE_VALIDACION);
    }

    /**
     * Solo solicitudes aprobadas
     */
    public function scopeAprobadas($query)
    {
        return $query->where('estado', self::ESTADO_APROBADO);
    }

    /**
     * Solo solicitudes rechazadas
     */
    public function scopeRechazadas($query)
    {
        return $query->where('estado', self::ESTADO_RECHAZADO);
    }

    /**
     * Solo con matrícula creada
     */
    public function scopeMatriculaCreada($query)
    {
        return $query->where('estado', self::ESTADO_MATRICULA_CREADA);
    }

    /**
     * Activas (no canceladas, no rechazadas definitivamente)
     */
    public function scopeActivas($query)
    {
        return $query->whereIn('estado', [
            self::ESTADO_REGISTRADO,
            self::ESTADO_PENDIENTE_VALIDACION,
            self::ESTADO_APROBADO,
            self::ESTADO_MATRICULA_CREADA,
        ]);
    }

    /**
     * Por estudiante
     */
    public function scopeDelEstudiante($query, $personaId)
    {
        return $query->where('persona_id', $personaId);
    }

    /**
     * Por participante externo
     */
    public function scopeDelParticipanteExterno($query, $participanteExternoId)
    {
        return $query->where('participante_externo_id', $participanteExternoId);
    }

    /**
     * Por curso
     */
    public function scopeDelCurso($query, $cursoAbiertoId)
    {
        return $query->where('curso_abierto_id', $cursoAbiertoId);
    }

    /**
     * Por estado
     */
    public function scopeConEstado($query, $estado)
    {
        return $query->where('estado', $estado);
    }

    /**
     * Búsqueda en nombre/email del solicitante
     */
    public function scopeSearch($query, $term)
    {
        return $query->whereHas('estudiante', function ($q) use ($term) {
            $q->where('nombres', 'ilike', "%{$term}%")
              ->orWhere('apellidos', 'ilike', "%{$term}%")
              ->orWhere('correo', 'ilike', "%{$term}%");
        })->orWhereHas('participanteExterno', function ($q) use ($term) {
            $q->where('nombres', 'ilike', "%{$term}%")
              ->orWhere('apellidos', 'ilike', "%{$term}%")
              ->orWhere('correo', 'ilike', "%{$term}%");
        });
    }

    // ========================================================================
    // MÉTODOS ÚTILES
    // ========================================================================

    /**
     * ¿Es estudiante registrado (con cuenta)?
     */
    public function esEstudiante(): bool
    {
        return !$this->es_participante_externo && !empty($this->persona_id);
    }

    /**
     * ¿Es participante externo?
     */
    public function esParticipanteExterno(): bool
    {
        return $this->es_participante_externo && !empty($this->participante_externo_id);
    }

    /**
     * ¿Está pendiente de validación?
     */
    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE_VALIDACION;
    }

    /**
     * ¿Fue aprobada?
     */
    public function fueAprobada(): bool
    {
        return $this->estado === self::ESTADO_APROBADO;
    }

    /**
     * ¿Fue rechazada?
     */
    public function fueRechazada(): bool
    {
        return $this->estado === self::ESTADO_RECHAZADO;
    }

    /**
     * ¿Tiene matrícula creada?
     */
    public function tieneMatricula(): bool
    {
        return $this->estado === self::ESTADO_MATRICULA_CREADA;
    }

    /**
     * ¿Fue cancelada?
     */
    public function fueCancelada(): bool
    {
        return $this->estado === self::ESTADO_CANCELADO;
    }

    /**
     * ¿Está activa?
     */
    public function estaActiva(): bool
    {
        return in_array($this->estado, [
            self::ESTADO_REGISTRADO,
            self::ESTADO_PENDIENTE_VALIDACION,
            self::ESTADO_APROBADO,
            self::ESTADO_MATRICULA_CREADA,
        ]);
    }

    /**
     * ¿Es válida?
     */
    public function esValida(): bool
    {
        return (($this->esEstudiante() && !empty($this->persona_id)) ||
                ($this->esParticipanteExterno() && !empty($this->participante_externo_id))) &&
               !empty($this->curso_abierto_id) &&
               $this->monto_solicitado > 0 &&
               in_array($this->tipo_pago, self::TIPOS_PAGO) &&
               in_array($this->estado, self::ESTADOS_VALIDOS);
    }

    /**
     * Obtener nombre del solicitante
     */
    public function obtenerNombreSolicitante(): string
    {
        if ($this->esEstudiante()) {
            $est = $this->estudiante;
            return $est ? "{$est->nombres} {$est->apellidos}" : 'Desconocido';
        }

        if ($this->esParticipanteExterno()) {
            $ext = $this->participanteExterno;
            return $ext ? "{$ext->nombres} {$ext->apellidos}" : 'Desconocido';
        }

        return 'Desconocido';
    }

    /**
     * Obtener correo del solicitante
     */
    public function obtenerCorreoSolicitante(): ?string
    {
        if ($this->esEstudiante()) {
            return $this->estudiante?->correo;
        }

        if ($this->esParticipanteExterno()) {
            return $this->participanteExterno?->correo;
        }

        return null;
    }

    /**
     * Obtener descripción del estado
     */
    public function obtenerDescripcionEstado(): string
    {
        $descripciones = [
            self::ESTADO_REGISTRADO => 'Registrado',
            self::ESTADO_PENDIENTE_VALIDACION => 'Pendiente Validación',
            self::ESTADO_APROBADO => 'Aprobado',
            self::ESTADO_RECHAZADO => 'Rechazado',
            self::ESTADO_MATRICULA_CREADA => 'Matrícula Creada',
            self::ESTADO_CANCELADO => 'Cancelado',
        ];

        return $descripciones[$this->estado] ?? 'Desconocido';
    }
}

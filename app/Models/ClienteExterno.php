<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ClienteExterno - Participante externo que se inscribe en cursos
 * Representa a personas que no tienen cuenta en el sistema pero desean inscribirse
 */
class ClienteExterno extends Model
{
    use HasUuids;

    protected $connection = 'pgsql';
    protected $table = 'people.clientes_externos';
    public $timestamps = false;

    protected $fillable = [
        'nombres',
        'apellidos',
        'cedula',
        'correo',
        'celular',
        'ciudad_id',
        'ciudad',
        'observaciones',
        'ocupacion',
        'direccion',
        'estado_civil',
        'fecha_nacimiento',
        'edad',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ========================================================================
    // RELACIONES
    // ========================================================================

    /**
     * Solicitudes de inscripción de este cliente externo
     */
    public function solicitudesInscripcion(): HasMany
    {
        return $this->hasMany(SolicitudInscripcion::class, 'participante_externo_id');
    }

    /**
     * Ciudad donde reside
     */
    public function ciudad()
    {
        return $this->belongsTo(Ciudad::class, 'ciudad_id', 'id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Filtrar por email
     */
    public function scopePorEmail($query, $correo)
    {
        return $query->where('correo', $correo);
    }

    /**
     * Filtrar por cédula
     */
    public function scopePorCedula($query, $cedula)
    {
        return $query->where('cedula', $cedula);
    }

    /**
     * Filtrar por ciudad
     */
    public function scopePorCiudad($query, $ciudadId)
    {
        return $query->where('ciudad_id', $ciudadId);
    }

    // ========================================================================
    // MÉTODOS UTILITARIOS
    // ========================================================================

    /**
     * Obtener nombre completo
     */
    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    /**
     * Obtener total de inscripciones pendientes
     */
    public function totalInscripcionesPendientes(): int
    {
        return $this->solicitudesInscripcion()
            ->where('estado', SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION)
            ->count();
    }

    /**
     * Obtener total de inscripciones aprobadas
     */
    public function totalInscripcionesAprobadas(): int
    {
        return $this->solicitudesInscripcion()
            ->where('estado', SolicitudInscripcion::ESTADO_APROBADO)
            ->count();
    }
}

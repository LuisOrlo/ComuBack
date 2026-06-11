<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Certificado extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'academic.certificados';
    protected $connection = 'pgsql';

    protected $fillable = [
        'estudiante_id',
        'catalogo_id',
        'curso_abierto_id',
        'modulo_id',
        'cedula_impresa',
        'fecha_emision',
        'codigo_certificado',
        'archivo_pdf_url',
        'estado',
        'fecha_entrega',
        'entregado_fisicamente',
        'verificaciones_count',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_entrega' => 'date',
        'entregado_fisicamente' => 'boolean',
        'verificaciones_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    const ESTADO_GENERADO = 'generado';
    const ESTADO_ENTREGADO = 'entregado';
    const ESTADO_BORRADO = 'borrado';

    const ESTADOS_VALIDOS = ['generado', 'entregado', 'borrado'];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $certificado) {
            if (empty($certificado->codigo_certificado)) {
                $certificado->codigo_certificado = self::generarCodigo();
            }
        });
    }

    public static function generarCodigo(): string
    {
        $anio = now()->year;
        $random = strtoupper(Str::random(6));
        $codigo = "CERT-{$anio}-{$random}";

        while (self::where('codigo_certificado', $codigo)->exists()) {
            $random = strtoupper(Str::random(6));
            $codigo = "CERT-{$anio}-{$random}";
        }

        return $codigo;
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'estudiante_id', 'id');
    }

    public function catalogoCurso(): BelongsTo
    {
        return $this->belongsTo(CatalogoCurso::class, 'catalogo_id', 'id');
    }

    public function cursoAbierto(): BelongsTo
    {
        return $this->belongsTo(CursoAbierto::class, 'curso_abierto_id', 'id');
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'modulo_id', 'id');
    }

    public function scopePorEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopePorCedula($query, string $cedula)
    {
        return $query->where('cedula_impresa', $cedula);
    }

    public function scopePorCodigo($query, string $codigo)
    {
        return $query->where('codigo_certificado', $codigo);
    }

    public function scopeDisponibles($query)
    {
        return $query->whereIn('estado', [self::ESTADO_GENERADO, self::ESTADO_ENTREGADO])
            ->whereNotNull('archivo_pdf_url');
    }

    public function tienePdf(): bool
    {
        return !is_null($this->archivo_pdf_url);
    }

    public function estaBorrado(): bool
    {
        return $this->estado === self::ESTADO_BORRADO;
    }

    public function incrementarVerificaciones(): void
    {
        $this->increment('verificaciones_count');
    }

    public function marcarEntregado(): void
    {
        $this->update([
            'estado' => self::ESTADO_ENTREGADO,
            'fecha_entrega' => now(),
        ]);
    }

    public function getDescripcionEstadoAttribute(): string
    {
        return match ($this->estado) {
            self::ESTADO_GENERADO => 'Generado',
            self::ESTADO_ENTREGADO => 'Entregado',
            self::ESTADO_BORRADO => 'Borrado',
            default => 'Desconocido',
        };
    }
}

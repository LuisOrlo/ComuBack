<?php

namespace App\Http\Resources\Estudiantes;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use App\Models\ArchivoEliminado;

class EstudianteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'cedula' => $this->cedula,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'correo' => $this->correo,
            'celular' => $this->celular,
            // La ciudad nueva se guarda mediante ciudad_id, pero aún existen
            // registros antiguos que conservan el nombre en personas.ciudad.
            // Si la relación está cargada y no encuentra registro, no debemos
            // ocultar ese valor legado mostrando "—".
            'ciudad' => $this->serializarCiudad(),
            'cedula_photo_url' => $this->cedula_photo_url
                ?: $this->resolveCedulaFromSolicitudes(),
            'cedula_purgado' => $this->isCedulaPurgado(),
            'ficha_registro_url' => $this->ficha_registro_url,
            'es_activo' => $this->es_activo,
            'total_cursos' => $this->relationLoaded('matriculas') ? $this->matriculas->count() : ($this->perfilEstudiante?->total_cursos ?? 0),
            'total_talleres' => $this->totalTalleres(),
            'estado_pago' => $this->calcularEstadoPago(),
            'saldo_pendiente' => $this->calcularSaldoPendiente(),
            'perfil_estudiante' => $this->whenLoaded('perfilEstudiante', function () {
                if (!$this->perfilEstudiante) {
                    return null;
                }

                $matriculas = $this->relationLoaded('matriculas') ? $this->matriculas : null;

                $primeraMatricula = $this->perfilEstudiante->primera_matricula;
                if (!$primeraMatricula && $matriculas && $matriculas->isNotEmpty()) {
                    $fechas = $matriculas->pluck('fecha_inscripcion')->filter()->map(fn($d) => $d instanceof Carbon ? $d : Carbon::parse($d))->sort();
                    $primeraMatricula = $fechas->first();
                }

                $ultimaMatricula = $this->perfilEstudiante->ultima_matricula;
                if (!$ultimaMatricula && $matriculas && $matriculas->isNotEmpty()) {
                    $fechas = $matriculas->pluck('fecha_inscripcion')->filter()->map(fn($d) => $d instanceof Carbon ? $d : Carbon::parse($d))->sort();
                    $ultimaMatricula = $fechas->last();
                }

                $totalCursos = $this->perfilEstudiante->total_cursos;
                if (!$totalCursos && $matriculas) {
                    $totalCursos = $matriculas->count();
                }

                $terminos = $this->resolveTerminos();

                return [
                    'id' => $this->perfilEstudiante->id,
                    'notas_internas' => $this->perfilEstudiante->notas_internas,
                    'primera_matricula' => $primeraMatricula instanceof Carbon ? $primeraMatricula->format('Y-m-d') : ($primeraMatricula ? Carbon::parse($primeraMatricula)->format('Y-m-d') : null),
                    'ultima_matricula' => $ultimaMatricula instanceof Carbon ? $ultimaMatricula->format('Y-m-d') : ($ultimaMatricula ? Carbon::parse($ultimaMatricula)->format('Y-m-d') : null),
                    'total_cursos' => $totalCursos,
                    'ocupacion' => $this->perfilEstudiante->ocupacion,
                    'direccion' => $this->perfilEstudiante->direccion,
                    'ciudad' => $this->perfilEstudiante->ciudad,
                    'estado_civil' => $this->perfilEstudiante->estado_civil,
                    'edad' => $this->perfilEstudiante->edad,
                    'nivel_educativo' => $this->perfilEstudiante->nivel_educativo,
                    'terminos_aceptados' => $terminos['aceptados'],
                    'fecha_terminos_aceptados' => $terminos['fecha'],
                ];
            }),
            'creado_en' => $this->created_at->toIso8601String(),
            'actualizado_en' => $this->updated_at->toIso8601String(),
        ];
    }

    private function serializarCiudad(): ?array
    {
        $relacion = $this->relationLoaded('ciudad') ? $this->getRelation('ciudad') : null;

        if ($relacion) {
            return [
                'id' => $relacion->id,
                'nombre' => $relacion->nombre,
                'pais' => $relacion->pais,
            ];
        }

        $nombreLegacy = $this->getRawOriginal('ciudad');

        return $nombreLegacy ? ['nombre' => $nombreLegacy] : null;
    }

    private function calcularEstadoPago(): string
    {
        $matriculas = $this->relationLoaded('matriculas') ? $this->matriculas : collect();
        $totalMatriculas = $matriculas->count();

        if ($totalMatriculas === 0) {
            return 'ninguno';
        }

        $cuentas = $matriculas->map(fn($m) => $m->cuentaPorCobrar)->filter();
        $estadosCuentas = $cuentas->pluck('estado')->unique();
        $estadosLineas = $matriculas->flatMap(fn($m) => $m->lineasPago->pluck('estado'))->unique();

        $tienePendiente = $estadosCuentas->contains('pendiente') || $estadosLineas->contains('pendiente');
        $tieneAbonado = $estadosCuentas->contains('abonado') || $estadosLineas->contains('abonado');
        $tienePagado = ($cuentas->count() > 0 && $estadosCuentas->every(fn($e) => $e === 'pagado'))
            || ($cuentas->isEmpty() && $estadosLineas->isNotEmpty() && $estadosLineas->every(fn($e) => $e === 'pagado'));

        if ($cuentas->count() < $totalMatriculas && $estadosLineas->isEmpty()) {
            return 'deudor';
        }
        if ($tienePendiente) {
            return 'deudor';
        }
        if ($tieneAbonado) {
            return 'abonado';
        }
        if ($tienePagado) {
            return 'al_dia';
        }
        return 'deudor';
    }

    private function calcularSaldoPendiente(): float
    {
        $matriculas = $this->relationLoaded('matriculas') ? $this->matriculas : collect();

        return $matriculas->sum(function ($m) {
            if ($m->cuentaPorCobrar) {
                return (float) ($m->cuentaPorCobrar->monto_total - $m->cuentaPorCobrar->monto_abonado);
            }
            return (float) ($m->cursoAbierto->precio_base ?? 0);
        });
    }

    private function totalTalleres(): int
    {
        if (!$this->cedula) return 0;
        return \App\Models\InscripcionTaller::where('cedula', $this->cedula)
            ->whereIn('estado', ['activo', 'completado'])
            ->count();
    }

    private function resolveCedulaFromSolicitudes(): ?string
    {
        $solicitud = \App\Models\SolicitudInscripcion::where('persona_id', $this->id)
            ->whereNotNull('archivo_cedula_url')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($solicitud?->archivo_cedula_url) {
            return $solicitud->archivo_cedula_url;
        }

        $inscripcion = \App\Models\InscripcionTaller::where('persona_id', $this->id)
            ->whereNotNull('cedula_url')
            ->orderBy('fecha_inscripcion', 'desc')
            ->first();

        return $inscripcion?->cedula_url;
    }

    private function isCedulaPurgado(): bool
    {
        if ($this->cedula_photo_url) {
            return ArchivoEliminado::archivoFueEliminado(
                \App\Models\Persona::class, $this->id, 'cedula_photo_url'
            );
        }

        $solicitud = \App\Models\SolicitudInscripcion::where('persona_id', $this->id)
            ->whereNotNull('archivo_cedula_url')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($solicitud) {
            return ArchivoEliminado::archivoFueEliminado(
                \App\Models\SolicitudInscripcion::class, $solicitud->id, 'archivo_cedula_url'
            );
        }

        $inscripcion = \App\Models\InscripcionTaller::where('persona_id', $this->id)
            ->whereNotNull('cedula_url')
            ->orderBy('fecha_inscripcion', 'desc')
            ->first();

        if ($inscripcion) {
            return ArchivoEliminado::archivoFueEliminado(
                \App\Models\InscripcionTaller::class, $inscripcion->id, 'cedula_url'
            );
        }

        return false;
    }

    private function resolveTerminos(): array
    {
        // 1. Si el perfil ya tiene terminos_aceptados como true explícito
        if (!empty($this->perfilEstudiante?->terminos_aceptados)) {
            return [
                'aceptados' => true,
                'fecha' => $this->perfilEstudiante->fecha_terminos_aceptados?->toIso8601String()
                    ?? $this->perfilEstudiante->created_at?->toIso8601String()
                    ?? $this->created_at?->toIso8601String(),
            ];
        }

        // 2. Buscar en SolicitudInscripcion asociada por persona_id o por cedula/correo
        $solicitudQuery = \App\Models\SolicitudInscripcion::query()
            ->whereNotNull('datos_declarados')
            ->where(function ($q) {
                $q->where('persona_id', $this->id);
                if (!empty($this->cedula)) {
                    $q->orWhere('datos_declarados->cedula', $this->cedula);
                }
                if (!empty($this->correo)) {
                    $q->orWhere('datos_declarados->correo', $this->correo);
                }
            })
            ->orderBy('created_at', 'desc')
            ->first();

        if ($solicitudQuery) {
            $declarados = is_array($solicitudQuery->datos_declarados) ? $solicitudQuery->datos_declarados : [];
            if (!empty($declarados['terminos_aceptados']) || !empty($declarados['acepta_terminos'])) {
                $fecha = $declarados['fecha_terminos_aceptados'] ?? $solicitudQuery->created_at;

                // Auto-sanar el perfil en la base de datos si ya tiene los términos aceptados
                if ($this->perfilEstudiante && empty($this->perfilEstudiante->terminos_aceptados)) {
                    try {
                        $this->perfilEstudiante->update([
                            'terminos_aceptados' => true,
                            'fecha_terminos_aceptados' => $fecha,
                        ]);
                    } catch (\Throwable $e) {}
                }

                return [
                    'aceptados' => true,
                    'fecha' => is_string($fecha) ? $fecha : ($fecha instanceof Carbon ? $fecha->toIso8601String() : Carbon::parse($fecha)->toIso8601String()),
                ];
            }
        }

        // 3. Buscar en ClienteExterno si existía previamente
        if (!empty($this->correo) || !empty($this->cedula)) {
            $clienteExterno = \App\Models\ClienteExterno::query()
                ->where(function ($q) {
                    if (!empty($this->correo)) $q->where('correo', $this->correo);
                    if (!empty($this->cedula)) $q->orWhere('cedula', $this->cedula);
                })
                ->where('terminos_aceptados', true)
                ->first();

            if ($clienteExterno) {
                $fechaExt = $clienteExterno->fecha_terminos_aceptados ?? $clienteExterno->created_at;
                if ($this->perfilEstudiante && empty($this->perfilEstudiante->terminos_aceptados)) {
                    try {
                        $this->perfilEstudiante->update([
                            'terminos_aceptados' => true,
                            'fecha_terminos_aceptados' => $fechaExt,
                        ]);
                    } catch (\Throwable $e) {}
                }

                return [
                    'aceptados' => true,
                    'fecha' => $fechaExt instanceof Carbon ? $fechaExt->toIso8601String() : ($fechaExt ? Carbon::parse($fechaExt)->toIso8601String() : null),
                ];
            }
        }

        return [
            'aceptados' => false,
            'fecha' => null,
        ];
    }
}

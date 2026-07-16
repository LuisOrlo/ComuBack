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
            'ciudad' => $this->ciudad ? (
                is_string($this->ciudad)
                    ? ['nombre' => $this->ciudad]
                    : ['id' => $this->ciudad->id, 'nombre' => $this->ciudad->nombre, 'pais' => $this->ciudad->pais]
            ) : null,
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

                return [
                    'id' => $this->perfilEstudiante->id,
                    'fecha_nacimiento' => $this->perfilEstudiante->fecha_nacimiento?->format('Y-m-d'),
                    'notas_internas' => $this->perfilEstudiante->notas_internas,
                    'primera_matricula' => $primeraMatricula instanceof Carbon ? $primeraMatricula->format('Y-m-d') : ($primeraMatricula ? Carbon::parse($primeraMatricula)->format('Y-m-d') : null),
                    'ultima_matricula' => $ultimaMatricula instanceof Carbon ? $ultimaMatricula->format('Y-m-d') : ($ultimaMatricula ? Carbon::parse($ultimaMatricula)->format('Y-m-d') : null),
                    'total_cursos' => $totalCursos,
                    'ocupacion' => $this->perfilEstudiante->ocupacion,
                    'direccion' => $this->perfilEstudiante->direccion,
                    'ciudad' => $this->perfilEstudiante->ciudad,
                    'estado_civil' => $this->perfilEstudiante->estado_civil,
                    'edad' => $this->perfilEstudiante->edad,
                ];
            }),
            'creado_en' => $this->created_at->toIso8601String(),
            'actualizado_en' => $this->updated_at->toIso8601String(),
        ];
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
}

<?php

namespace App\Http\Resources\Estudiantes;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

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
            'cedula_photo_url' => $this->cedula_photo_url,
            'ficha_registro_url' => $this->ficha_registro_url,
            'es_activo' => $this->es_activo,
            'total_cursos' => $this->relationLoaded('matriculas') ? $this->matriculas->count() : ($this->perfilEstudiante?->total_cursos ?? 0),
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
}

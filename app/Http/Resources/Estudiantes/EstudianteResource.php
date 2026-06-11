<?php

namespace App\Http\Resources\Estudiantes;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'ciudad' => $this->ciudad ? [
                'id' => $this->ciudad->id,
                'nombre' => $this->ciudad->nombre,
                'pais' => $this->ciudad->pais,
            ] : null,
            'cedula_photo_url' => $this->cedula_photo_url,
            'ficha_registro_url' => $this->ficha_registro_url,
            'es_activo' => $this->es_activo,
            'perfil_estudiante' => $this->whenLoaded('perfilEstudiante', function () {
                if (!$this->perfilEstudiante) {
                    return null;
                }

                return [
                    'id' => $this->perfilEstudiante->id,
                    'fecha_nacimiento' => $this->perfilEstudiante->fecha_nacimiento?->format('Y-m-d'),
                    'notas_internas' => $this->perfilEstudiante->notas_internas,
                    'primera_matricula' => $this->perfilEstudiante->primera_matricula?->format('Y-m-d'),
                    'ultima_matricula' => $this->perfilEstudiante->ultima_matricula?->format('Y-m-d'),
                    'total_cursos' => $this->perfilEstudiante->total_cursos,
                ];
            }),
            'creado_en' => $this->created_at->toIso8601String(),
            'actualizado_en' => $this->updated_at->toIso8601String(),
        ];
    }
}

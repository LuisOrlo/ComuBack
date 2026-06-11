<?php

namespace App\Http\Resources\Academic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CursoAbiertoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre_instancia' => $this->nombre_instancia,
            'semestre' => $this->semestre,
            'fecha_inicio' => $this->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $this->fecha_fin?->format('Y-m-d'),
            'capacidad_maxima' => $this->capacidad_maxima,
            'estudiantes_inscritos' => $this->estudiantes_inscritos ?? $this->matriculas_count,
            'precio_base' => $this->precio_base,
            'modalidad' => $this->modalidad,
            'es_activo' => $this->es_activo,
            'observaciones' => $this->observaciones,
            'catalogo' => $this->whenLoaded('catalogo', fn() => [
                'id' => $this->catalogo->id,
                'nombre' => $this->catalogo->nombre,
            ]),
            'docente' => $this->whenLoaded('docente', fn() => $this->docente ? [
                'id' => $this->docente->id,
                'nombre' => $this->docente->nombres . ' ' . ($this->docente->apellidos ?? ''),
            ] : null),
            'horario' => $this->whenLoaded('horario', fn() => $this->horario),
            'ciudad' => $this->whenLoaded('ciudad', fn() => $this->ciudad ? [
                'id' => $this->ciudad->id,
                'nombre' => $this->ciudad->nombre,
            ] : null),
        ];
    }
}

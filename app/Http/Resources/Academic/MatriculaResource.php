<?php

namespace App\Http\Resources\Academic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatriculaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin' => $this->fecha_fin,
            'precio_total' => $this->precio_total,
            'tipo_pago' => $this->tipo_pago,
            'calificacion_final' => $this->calificacion_final,
            'observaciones' => $this->observaciones,
            'estudiante' => $this->whenLoaded('estudiante', fn() => $this->estudiante ? [
                'id' => $this->estudiante->id,
                'nombre' => $this->estudiante->nombres . ' ' . ($this->estudiante->apellidos ?? ''),
                'cedula' => $this->estudiante->cedula,
                'correo' => $this->estudiante->correo,
            ] : null),
            'curso' => $this->whenLoaded('cursoAbierto', fn() => $this->cursoAbierto ? [
                'id' => $this->cursoAbierto->id,
                'nombre' => $this->cursoAbierto->nombre_instancia,
            ] : null),
            'horario' => $this->whenLoaded('horario', fn() => $this->horario),
            'notas' => $this->whenLoaded('notas'),
        ];
    }
}

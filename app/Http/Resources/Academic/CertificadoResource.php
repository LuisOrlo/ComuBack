<?php

namespace App\Http\Resources\Academic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo_certificado' => $this->codigo_certificado,
            'cedula_impresa' => $this->cedula_impresa,
            'fecha_emision' => $this->fecha_emision?->format('Y-m-d'),
            'fecha_entrega' => $this->fecha_entrega?->format('Y-m-d'),
            'estado' => $this->estado,
            'entregado_fisicamente' => $this->entregado_fisicamente,
            'archivo_pdf_url' => $this->archivo_pdf_url,
            'estudiante' => $this->whenLoaded('estudiante', fn() => $this->estudiante ? [
                'id' => $this->estudiante->id,
                'nombre' => $this->estudiante->nombres . ' ' . ($this->estudiante->apellidos ?? ''),
                'cedula' => $this->estudiante->cedula,
            ] : null),
            'catalogo' => $this->whenLoaded('catalogoCurso', fn() => $this->catalogoCurso ? [
                'id' => $this->catalogoCurso->id,
                'nombre' => $this->catalogoCurso->nombre,
            ] : null),
            'curso' => $this->whenLoaded('cursoAbierto', fn() => $this->cursoAbierto ? [
                'id' => $this->cursoAbierto->id,
                'nombre' => $this->cursoAbierto->nombre_instancia,
            ] : null),
        ];
    }
}

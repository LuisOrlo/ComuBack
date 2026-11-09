<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstadisticasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'periodo' => $this['periodo'] ?? null,
            'unidad_negocio' => $this['unidad_negocio'] ?? 'todos',
            'metricas' => $this['metricas'] ?? null,
            'ingresos_vs_egresos' => $this['ingresos_vs_egresos'] ?? [],
            'distribucion_categorias' => $this['distribucion_categorias'] ?? [],
            'metodo_pago' => $this['metodo_pago'] ?? [],
            'dias_semana' => $this['dias_semana'] ?? [],
            'catalogos_top' => $this['catalogos_top'] ?? [],
            'ciudades_top' => $this['ciudades_top'] ?? [],
            'modalidad' => $this['modalidad'] ?? null,
            'cobranza' => $this['cobranza'] ?? null,
            'actividad_servicios' => $this['actividad_servicios'] ?? [],
            'top_estudiantes' => $this['top_estudiantes'] ?? [],
            'insight_text' => $this['insight_text'] ?? '',
        ];
    }
}

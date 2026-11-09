<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEstadisticasRequest;
use App\Http\Resources\EstadisticasResource;
use App\Services\EstadisticasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EstadisticasController extends Controller
{
    public function getEstadisticas(StoreEstadisticasRequest $request): JsonResponse
    {
        $desde = $request->get('desde', now()->startOfMonth()->format('Y-m-d'));
        $hasta = $request->get('hasta', now()->endOfMonth()->format('Y-m-d'));
        $unidad = $request->get('unidad_negocio', 'todos');

        $service = new EstadisticasService($desde, $hasta, $unidad);

        $metricas = $this->safeCall(fn() => $service->metricasBase(), []);
        $metricasEst = $unidad !== 'servicios' ? $this->safeCall(fn() => $service->metricasEstudiantes(), []) : [];
        $flujo = $this->safeCall(fn() => $service->flujoFinanciero(), []);
        $distribucion = $this->safeCall(fn() => $service->distribucionCategorias(), []);

        $data = [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'unidad_negocio' => $unidad,
            'metricas' => array_merge($metricas, $metricasEst),
            'ingresos_vs_egresos' => $flujo,
            'distribucion_categorias' => $distribucion,
            'metodo_pago' => $this->safeCall(fn() => $service->metodoPago(), []),
            'dias_semana' => $this->safeCall(fn() => $service->diasSemana(), []),
            'catalogos_top' => $unidad !== 'servicios' ? $this->safeCall(fn() => $service->catalogosTop(), []) : [],
            'ciudades_top' => $unidad !== 'servicios' ? $this->safeCall(fn() => $service->ciudadesTop(), []) : [],
            'modalidad' => $unidad !== 'servicios' ? $this->safeCall(fn() => $service->modalidadComparativa(), null) : null,
            'cobranza' => $unidad !== 'servicios' ? $this->safeCall(fn() => $service->cobranza(), null) : null,
            'actividad_servicios' => $unidad !== 'academia' ? $this->safeCall(fn() => $service->actividadServicios(), []) : [],
            'top_estudiantes' => $this->safeCall(fn() => $service->topEstudiantes(), []),
            'insight_text' => $this->safeCall(fn() => $service->insightText($metricas, $flujo, $distribucion), ''),
        ];

        return response()->json(new EstadisticasResource($data));
    }

    public function getCatalogoDetalle(string $id, Request $request): JsonResponse
    {
        $desde = $request->get('desde', now()->startOfMonth()->format('Y-m-d'));
        $hasta = $request->get('hasta', now()->endOfMonth()->format('Y-m-d'));

        try {
            $service = new EstadisticasService($desde, $hasta);
            $data = $service->catalogoDetalle($id);
            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error en getCatalogoDetalle', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['mensaje' => 'Error al cargar el detalle del catálogo'], 500);
        }
    }

    public function getEstudianteDetalle(string $id, Request $request): JsonResponse
    {
        $desde = $request->get('desde', now()->startOfMonth()->format('Y-m-d'));
        $hasta = $request->get('hasta', now()->endOfMonth()->format('Y-m-d'));

        try {
            $service = new EstadisticasService($desde, $hasta);
            $data = $service->estudianteDetalle($id);
            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error en getEstudianteDetalle', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['mensaje' => 'Error al cargar el detalle del estudiante'], 500);
        }
    }

    private function safeCall(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (\Exception $e) {
            Log::error('EstadisticasService error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $default;
        }
    }
}

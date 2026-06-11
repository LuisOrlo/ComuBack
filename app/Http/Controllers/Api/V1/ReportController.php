<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Http\Requests\ReportRequest;
use Illuminate\Http\JsonResponse;

/**
 * ReportController
 * 
 * Maneja reportes académicos:
 * - Reporte de asistencia
 * - Reporte de desempeño
 * - Reporte de progreso
 * - Resumen académico completo
 */
class ReportController extends Controller
{
    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * GET /api/v1/academic/reports/asistencia
     * 
     * Reporte de asistencia
     * 
     * Query parameters:
     * - curso_abierto_id: uuid (opcional)
     * - estudiante_id: uuid (opcional)
     * - fecha_inicio: Y-m-d (opcional)
     * - fecha_fin: Y-m-d (opcional)
     * - formato: json|csv|pdf (default: json)
     */
    public function reporteAsistencia(ReportRequest $request): JsonResponse
    {
        try {
            $cursoAbiertoId = $request->validated('curso_abierto_id');
            $estudianteId = $request->validated('estudiante_id');
            $fechaInicio = $request->validated('fecha_inicio') 
                ? new \DateTime($request->validated('fecha_inicio'))
                : null;
            $fechaFin = $request->validated('fecha_fin')
                ? new \DateTime($request->validated('fecha_fin'))
                : null;

            $reporte = $this->reportService->reporteAsistencia(
                $cursoAbiertoId,
                $estudianteId,
                $fechaInicio,
                $fechaFin
            );

            return response()->json($reporte);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al generar reporte de asistencia',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/academic/reports/desempeño
     * 
     * Reporte de desempeño académico
     * 
     * Muestra:
     * - Calificación promedio por estudiante
     * - Distribución de calificaciones
     * - Estudiantes con bajo/alto desempeño
     */
    public function reporteDesempeño(ReportRequest $request): JsonResponse
    {
        try {
            $cursoAbiertoId = $request->validated('curso_abierto_id');
            $estudianteId = $request->validated('estudiante_id');
            $fechaInicio = $request->validated('fecha_inicio')
                ? new \DateTime($request->validated('fecha_inicio'))
                : null;
            $fechaFin = $request->validated('fecha_fin')
                ? new \DateTime($request->validated('fecha_fin'))
                : null;

            $reporte = $this->reportService->reporteDesempeño(
                $cursoAbiertoId,
                $estudianteId,
                $fechaInicio,
                $fechaFin
            );

            return response()->json($reporte);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al generar reporte de desempeño',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/academic/reports/progreso
     * 
     * Reporte de progreso en cursos
     * 
     * Muestra:
     * - % completitud de cursos
     * - Módulos completados vs pendientes
     * - Proyección de finalización
     */
    public function reporteProgreso(ReportRequest $request): JsonResponse
    {
        try {
            $cursoAbiertoId = $request->validated('curso_abierto_id');
            $estudianteId = $request->validated('estudiante_id');

            $reporte = $this->reportService->reporteProgreso(
                $cursoAbiertoId,
                $estudianteId
            );

            return response()->json($reporte);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al generar reporte de progreso',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/academic/reports/resumen-academico
     * 
     * Resumen académico completo
     * 
     * Combina:
     * - Asistencia
     * - Desempeño
     * - Progreso
     * - Recomendaciones
     */
    public function resumenAcademico(ReportRequest $request): JsonResponse
    {
        try {
            $cursoAbiertoId = $request->validated('curso_abierto_id');
            $estudianteId = $request->validated('estudiante_id');

            $reporte = $this->reportService->resumenAcademico(
                $cursoAbiertoId,
                $estudianteId
            );

            return response()->json($reporte);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al generar resumen académico',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/academic/reports/tipos-disponibles
     * 
     * Obtener información sobre tipos de reportes disponibles
     */
    public function tiposDisponibles(): JsonResponse
    {
        return response()->json([
            'tipos_reportes' => [
                [
                    'tipo' => 'asistencia',
                    'nombre' => 'Reporte de Asistencia',
                    'descripcion' => 'Porcentaje de asistencia por estudiante y curso',
                    'parametros' => [
                        'curso_abierto_id' => 'uuid (opcional)',
                        'estudiante_id' => 'uuid (opcional)',
                        'fecha_inicio' => 'Y-m-d (opcional)',
                        'fecha_fin' => 'Y-m-d (opcional)',
                    ],
                    'metricas' => [
                        'Total de sesiones',
                        'Asistencias',
                        'Inasistencias',
                        'Porcentaje asistencia',
                        'Alertas de baja asistencia',
                    ],
                ],
                [
                    'tipo' => 'desempeño',
                    'nombre' => 'Reporte de Desempeño',
                    'descripcion' => 'Calificaciones y distribución de notas',
                    'parametros' => [
                        'curso_abierto_id' => 'uuid (opcional)',
                        'estudiante_id' => 'uuid (opcional)',
                        'fecha_inicio' => 'Y-m-d (opcional)',
                        'fecha_fin' => 'Y-m-d (opcional)',
                    ],
                    'metricas' => [
                        'Calificación promedio',
                        'Calificación ponderada',
                        'Distribución de calificaciones',
                        'Estado de desempeño',
                    ],
                ],
                [
                    'tipo' => 'progreso',
                    'nombre' => 'Reporte de Progreso',
                    'descripcion' => 'Avance en completitud de cursos',
                    'parametros' => [
                        'curso_abierto_id' => 'uuid (opcional)',
                        'estudiante_id' => 'uuid (opcional)',
                    ],
                    'metricas' => [
                        'Módulos completados',
                        'Módulos pendientes',
                        'Porcentaje de progreso',
                        'Estado (en tiempo / atrasado)',
                    ],
                ],
                [
                    'tipo' => 'resumen_academico',
                    'nombre' => 'Resumen Académico Completo',
                    'descripcion' => 'Combinación de asistencia, desempeño y progreso',
                    'parametros' => [
                        'curso_abierto_id' => 'uuid (opcional)',
                        'estudiante_id' => 'uuid (opcional)',
                    ],
                    'metricas' => 'Todas las anteriores + recomendaciones',
                ],
            ],
            'formatos_disponibles' => ['json', 'csv', 'pdf'],
        ]);
    }

    /**
     * GET /api/v1/academic/reports/comparativa-estudiantes
     * 
     * Comparativa de desempeño entre estudiantes
     */
    public function comparativaEstudiantes(ReportRequest $request): JsonResponse
    {
        try {
            $cursoAbiertoId = $request->validated('curso_abierto_id');

            if (!$cursoAbiertoId) {
                return response()->json([
                    'error' => 'Curso requerido para comparativa',
                ], 400);
            }

            $desempeño = $this->reportService->reporteDesempeño($cursoAbiertoId);
            $asistencia = $this->reportService->reporteAsistencia($cursoAbiertoId);

            $comparativa = [];
            foreach ($desempeño['detalle_por_estudiante'] as $estudiante) {
                $asistenciaEstudiante = collect($asistencia['detalle_por_estudiante'])
                    ->firstWhere('estudiante', $estudiante['estudiante']);

                $comparativa[] = [
                    'estudiante' => $estudiante['estudiante'],
                    'calificacion_promedio' => $estudiante['calificacion_promedio'],
                    'calificacion_ponderada' => $estudiante['calificacion_ponderada'],
                    'asistencia_porcentaje' => $asistenciaEstudiante['porcentaje_asistencia'] ?? 0,
                    'desempeño' => $estudiante['estado_desempeño'],
                    'asistencia_estado' => $asistenciaEstudiante['estado_alerta'] ?? 'normal',
                ];
            }

            // Ordenar por calificación ponderada
            usort($comparativa, fn($a, $b) => $b['calificacion_ponderada'] <=> $a['calificacion_ponderada']);

            return response()->json([
                'tipo' => 'comparativa_estudiantes',
                'curso' => $cursoAbiertoId,
                'total_estudiantes' => count($comparativa),
                'data' => $comparativa,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al generar comparativa',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

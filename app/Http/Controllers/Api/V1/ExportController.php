<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ExportService;
use App\Http\Requests\ExportRequest;
use App\Traits\Auditable;

/**
 * ExportController
 * 
 * Maneja exportaciones de datos:
 * - Calificaciones (CSV, PDF, Excel)
 * - Asistencia (CSV, PDF, Excel)
 * - Horarios (CSV, PDF, Excel)
 * - Reporte completo (CSV, PDF, Excel)
 */
class ExportController extends Controller
{
    use Auditable;

    protected ExportService $exportService;

    public function __construct(ExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * POST /api/v1/academic/export
     * 
     * Exportar datos según tipo y formato
     * 
     * Query parameters:
     * - format: csv|pdf|excel
     * - tipo_datos: calificaciones|asistencia|horarios|todos
     * - curso_abierto_id: uuid (opcional)
     * - filtro_estado: activo|completado|retirado|reprobado (opcional)
     * - fecha_inicio: Y-m-d (opcional)
     * - fecha_fin: Y-m-d (opcional)
     */
    public function export(ExportRequest $request)
    {
        try {
            $formato = $request->validated('format');
            $tipoDatos = $request->validated('tipo_datos');
            $cursoAbiertoId = $request->validated('curso_abierto_id');
            $filtroEstado = $request->validated('filtro_estado');

            $this->audit('exportacion_datos', [
                'tipo' => $tipoDatos,
                'formato' => $formato,
                'curso_id' => $cursoAbiertoId,
            ]);

            return match ($tipoDatos) {
                'calificaciones' => $this->exportService->exportCalificaciones(
                    $cursoAbiertoId,
                    $filtroEstado,
                    $formato
                ),
                'asistencia' => $this->exportService->exportAsistencia(
                    $cursoAbiertoId,
                    $filtroEstado,
                    $formato
                ),
                'horarios' => $this->exportService->exportHorarios(
                    $cursoAbiertoId,
                    $formato
                ),
                'todos' => $this->exportarTodo($formato, $cursoAbiertoId, $filtroEstado),
                default => response()->json(['error' => 'Tipo de datos no válido'], 400),
            };
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al exportar datos',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Exportar todos los datos
     * 
     * Para CSV/Excel: retorna array combinado
     * Para PDF: retorna PDF de múltiples páginas
     */
    private function exportarTodo(string $formato, ?string $cursoAbiertoId, ?string $filtroEstado)
    {
        if ($formato === 'pdf') {
            return $this->exportService->exportTodo($cursoAbiertoId, $filtroEstado, 'pdf');
        }

        $datos = $this->exportService->exportTodo($cursoAbiertoId, $filtroEstado, 'array');

        return response()->json([
            'message' => 'Datos exportados',
            'data' => $datos,
        ]);
    }

    /**
     * GET /api/v1/academic/export/formato-csv
     * 
     * Descargar template CSV para importación masiva
     */
    public function downloadCsvTemplate()
    {
        $csv = "estudiante_id,modulo_id,calificacion,observaciones\n";
        $csv .= "uuid,uuid,0.0,\"Observaciones opcionales\"\n";

        $filename = 'template_notas_' . now()->format('Y-m-d') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /api/v1/academic/export/formatos-disponibles
     * 
     * Obtener información sobre formatos disponibles
     */
    public function formatosDisponibles()
    {
        return response()->json([
            'formatos' => [
                [
                    'formato' => 'csv',
                    'descripcion' => 'Valores separados por comas',
                    'extension' => '.csv',
                    'ventajas' => ['Compatible con Excel', 'Ligero', 'Fácil de procesar'],
                    'desventajas' => ['Sin formato', 'Sin estilos'],
                ],
                [
                    'formato' => 'pdf',
                    'descripcion' => 'Documento portátil',
                    'extension' => '.pdf',
                    'ventajas' => ['Formato fijo', 'Profesional', 'Imprimible'],
                    'desventajas' => ['No editable', 'Más pesado'],
                ],
                [
                    'formato' => 'excel',
                    'descripcion' => 'Hoja de cálculo',
                    'extension' => '.xlsx',
                    'ventajas' => ['Editable', 'Fórmulas', 'Gráficos'],
                    'desventajas' => ['Más pesado', 'Requiere software'],
                ],
            ],
            'tipos_datos' => [
                [
                    'tipo' => 'calificaciones',
                    'descripcion' => 'Notas por estudiante y módulo',
                    'campos' => ['Estudiante', 'Curso', 'Módulo', 'Calificación', 'Ponderación'],
                ],
                [
                    'tipo' => 'asistencia',
                    'descripcion' => 'Registros de asistencia',
                    'campos' => ['Estudiante', 'Curso', 'Asistencias', 'Inasistencias', 'Porcentaje'],
                ],
                [
                    'tipo' => 'horarios',
                    'descripcion' => 'Horarios de clases',
                    'campos' => ['Curso', 'Profesor', 'Días', 'Hora inicio', 'Hora fin', 'Aula'],
                ],
                [
                    'tipo' => 'todos',
                    'descripcion' => 'Datos completos (calificaciones + asistencia + horarios)',
                    'campos' => 'Todos los anteriores',
                ],
            ],
        ]);
    }
}

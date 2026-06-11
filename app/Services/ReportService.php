<?php

namespace App\Services;

use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ReportService
 * 
 * Servicio para generar reportes académicos:
 * - Reporte de asistencia
 * - Reporte de desempeño
 * - Reporte de progreso
 * - Resumen académico
 */
class ReportService
{
    /**
     * Reporte de asistencia
     * 
     * Muestra:
     * - % asistencia por estudiante
     * - % asistencia por curso
     * - Tendencia de asistencia
     * - Alertas de baja asistencia
     */
    public function reporteAsistencia(
        ?string $cursoAbiertoId = null,
        ?string $estudianteId = null,
        ?\DateTime $fechaInicio = null,
        ?\DateTime $fechaFin = null
    ): array {
        $query = Matricula::with(['estudiante', 'curso_abierto']);

        if ($cursoAbiertoId) {
            $query->where('curso_abierto_id', $cursoAbiertoId);
        }

        if ($estudianteId) {
            $query->where('estudiante_id', $estudianteId);
        }

        if ($fechaInicio) {
            $query->whereDate('fecha_inicio', '>=', $fechaInicio);
        }

        if ($fechaFin) {
            $query->whereDate('fecha_fin', '<=', $fechaFin);
        }

        $matriculas = $query->get();

        $asistenciaPorEstudiante = $matriculas->map(function ($matricula) {
            $totalSesiones = $matricula->curso_abierto->horarios->count();
            $asistencias = $matricula->asistencias()->count();
            $inasistencias = $totalSesiones - $asistencias;
            $porcentaje = $totalSesiones > 0 ? ($asistencias / $totalSesiones) * 100 : 0;

            return [
                'estudiante_id' => $matricula->estudiante_id,
                'estudiante' => $matricula->estudiante->nombre,
                'curso' => $matricula->curso_abierto->nombre,
                'total_sesiones' => $totalSesiones,
                'asistencias' => $asistencias,
                'inasistencias' => $inasistencias,
                'porcentaje_asistencia' => round($porcentaje, 2),
                'estado_alerta' => $porcentaje < 80 ? 'baja_asistencia' : 'normal',
            ];
        });

        $asistenciaPromedio = $asistenciaPorEstudiante->avg('porcentaje_asistencia');
        $estudiantesAlerta = $asistenciaPorEstudiante->where('estado_alerta', 'baja_asistencia')->count();

        return [
            'tipo' => 'asistencia',
            'generado' => now(),
            'resumen' => [
                'total_estudiantes' => $matriculas->count(),
                'asistencia_promedio' => round($asistenciaPromedio, 2),
                'estudiantes_en_alerta' => $estudiantesAlerta,
                'porcentaje_alerta' => round(($estudiantesAlerta / max(1, $matriculas->count())) * 100, 2),
            ],
            'detalle_por_estudiante' => $asistenciaPorEstudiante->toArray(),
            'detalle_por_curso' => $this->agruparAsistenciaPorCurso($asistenciaPorEstudiante),
        ];
    }

    /**
     * Reporte de desempeño
     * 
     * Muestra:
     * - Calificación promedio por estudiante
     * - Calificación promedio por curso
     * - Distribución de calificaciones
     * - Estudiantes con bajo desempeño
     */
    public function reporteDesempeño(
        ?string $cursoAbiertoId = null,
        ?string $estudianteId = null,
        ?\DateTime $fechaInicio = null,
        ?\DateTime $fechaFin = null
    ): array {
        $query = Nota::with(['matricula', 'modulo', 'matricula.curso_abierto', 'matricula.estudiante']);

        if ($cursoAbiertoId) {
            $query->whereHas('matricula', function ($q) use ($cursoAbiertoId) {
                $q->where('curso_abierto_id', $cursoAbiertoId);
            });
        }

        if ($estudianteId) {
            $query->whereHas('matricula', function ($q) use ($estudianteId) {
                $q->where('estudiante_id', $estudianteId);
            });
        }

        if ($fechaInicio) {
            $query->whereDate('created_at', '>=', $fechaInicio);
        }

        if ($fechaFin) {
            $query->whereDate('created_at', '<=', $fechaFin);
        }

        $notas = $query->get();

        $desempenioPorEstudiante = [];
        foreach ($notas->groupBy('matricula_id') as $matriculaId => $notasEstudiante) {
            $matricula = $notasEstudiante->first()->matricula;
            $calificacionPromedio = $notasEstudiante->avg('calificacion');
            $calificacionPonderada = 0;

            foreach ($notasEstudiante as $nota) {
                $calificacionPonderada += $nota->calificacion * ($nota->modulo->ponderacion / 100);
            }

            $desempenioPorEstudiante[] = [
                'estudiante_id' => $matricula->estudiante_id,
                'estudiante' => $matricula->estudiante->nombre,
                'curso' => $matricula->curso_abierto->nombre,
                'calificacion_promedio' => round($calificacionPromedio, 2),
                'calificacion_ponderada' => round($calificacionPonderada, 2),
                'cantidad_modulos' => $notasEstudiante->count(),
                'estado_desempeño' => $calificacionPromedio >= 4.0 ? 'excelente' : 
                                     ($calificacionPromedio >= 3.5 ? 'bueno' : 
                                      ($calificacionPromedio >= 3.0 ? 'regular' : 'bajo')),
            ];
        }

        // Ordenar por calificación ponderada
        usort($desempenioPorEstudiante, function ($a, $b) {
            return $b['calificacion_ponderada'] <=> $a['calificacion_ponderada'];
        });

        $promedio = array_sum(array_column($desempenioPorEstudiante, 'calificacion_promedio')) / max(1, count($desempenioPorEstudiante));

        return [
            'tipo' => 'desempeño',
            'generado' => now(),
            'resumen' => [
                'total_estudiantes' => count(array_unique(array_column($desempenioPorEstudiante, 'estudiante'))),
                'calificacion_promedio_general' => round($promedio, 2),
                'estudiantes_bajo_desempeño' => count(array_filter($desempenioPorEstudiante, fn($d) => $d['estado_desempeño'] === 'bajo')),
                'estudiantes_excelente_desempeño' => count(array_filter($desempenioPorEstudiante, fn($d) => $d['estado_desempeño'] === 'excelente')),
            ],
            'distribucion_calificaciones' => $this->generarDistribucionCalificaciones($notas),
            'detalle_por_estudiante' => $desempenioPorEstudiante,
        ];
    }

    /**
     * Reporte de progreso
     * 
     * Muestra:
     * - % completitud de cursos
     * - Módulos completados vs pendientes
     * - Progreso por estudiante
     * - Proyección de finalización
     */
    public function reporteProgreso(
        ?string $cursoAbiertoId = null,
        ?string $estudianteId = null
    ): array {
        $query = Matricula::with(['estudiante', 'curso_abierto', 'curso_abierto.modulos', 'notas']);

        if ($cursoAbiertoId) {
            $query->where('curso_abierto_id', $cursoAbiertoId);
        }

        if ($estudianteId) {
            $query->where('estudiante_id', $estudianteId);
        }

        $matriculas = $query->get();

        $progresoPorEstudiante = $matriculas->map(function ($matricula) {
            $totalModulos = $matricula->curso_abierto->modulos->count();
            $modulosConNota = $matricula->notas->count();
            $porcentajeProgreso = $totalModulos > 0 ? ($modulosConNota / $totalModulos) * 100 : 0;

            $diasDesdeInicio = now()->diffInDays($matricula->fecha_inicio);
            $diasTotales = $matricula->fecha_fin ? $matricula->fecha_fin->diffInDays($matricula->fecha_inicio) : 90;
            $porcentajeTiempo = min(100, ($diasDesdeInicio / max(1, $diasTotales)) * 100);

            $proyeccionCompleta = $porcentajeProgreso >= ($porcentajeTiempo * 0.8);

            return [
                'estudiante_id' => $matricula->estudiante_id,
                'estudiante' => $matricula->estudiante->nombre,
                'curso' => $matricula->curso_abierto->nombre,
                'total_modulos' => $totalModulos,
                'modulos_completados' => $modulosConNota,
                'modulos_pendientes' => $totalModulos - $modulosConNota,
                'porcentaje_progreso' => round($porcentajeProgreso, 2),
                'porcentaje_tiempo_transcurrido' => round($porcentajeTiempo, 2),
                'dias_desde_inicio' => $diasDesdeInicio,
                'dias_totales' => $diasTotales,
                'estado_progreso' => $proyeccionCompleta ? 'en_tiempo' : 'atrasado',
                'fecha_inicio' => $matricula->fecha_inicio->format('Y-m-d'),
                'fecha_fin_proyectada' => $matricula->fecha_fin?->format('Y-m-d') ?? 'N/A',
            ];
        });

        $progresoPromedio = $progresoPorEstudiante->avg('porcentaje_progreso');
        $estudiantesAtrasados = $progresoPorEstudiante->where('estado_progreso', 'atrasado')->count();

        return [
            'tipo' => 'progreso',
            'generado' => now(),
            'resumen' => [
                'total_estudiantes' => $matriculas->count(),
                'progreso_promedio' => round($progresoPromedio, 2),
                'estudiantes_en_tiempo' => $matriculas->count() - $estudiantesAtrasados,
                'estudiantes_atrasados' => $estudiantesAtrasados,
                'porcentaje_atrasados' => round(($estudiantesAtrasados / max(1, $matriculas->count())) * 100, 2),
            ],
            'detalle_por_estudiante' => $progresoPorEstudiante->toArray(),
        ];
    }

    /**
     * Resumen académico completo
     * 
     * Combina:
     * - Asistencia
     * - Desempeño
     * - Progreso
     * - Recomendaciones
     */
    public function resumenAcademico(
        ?string $cursoAbiertoId = null,
        ?string $estudianteId = null
    ): array {
        $asistencia = $this->reporteAsistencia($cursoAbiertoId, $estudianteId);
        $desempeño = $this->reporteDesempeño($cursoAbiertoId, $estudianteId);
        $progreso = $this->reporteProgreso($cursoAbiertoId, $estudianteId);

        $recomendaciones = $this->generarRecomendaciones($asistencia, $desempeño, $progreso);

        return [
            'tipo' => 'resumen_academico',
            'generado' => now(),
            'asistencia' => $asistencia,
            'desempeño' => $desempeño,
            'progreso' => $progreso,
            'recomendaciones' => $recomendaciones,
        ];
    }

    /**
     * Generar distribución de calificaciones
     */
    private function generarDistribucionCalificaciones(Collection $notas): array
    {
        $distribucion = [
            '0.0-1.0' => 0,
            '1.1-2.0' => 0,
            '2.1-3.0' => 0,
            '3.1-4.0' => 0,
            '4.1-5.0' => 0,
        ];

        foreach ($notas as $nota) {
            $cal = $nota->calificacion;
            if ($cal <= 1.0) $distribucion['0.0-1.0']++;
            elseif ($cal <= 2.0) $distribucion['1.1-2.0']++;
            elseif ($cal <= 3.0) $distribucion['2.1-3.0']++;
            elseif ($cal <= 4.0) $distribucion['3.1-4.0']++;
            else $distribucion['4.1-5.0']++;
        }

        $total = $notas->count();
        return array_map(fn($count) => $total > 0 ? round(($count / $total) * 100, 2) : 0, $distribucion);
    }

    /**
     * Agrupar asistencia por curso
     */
    private function agruparAsistenciaPorCurso($asistenciaPorEstudiante): array
    {
        $porCurso = [];

        foreach ($asistenciaPorEstudiante as $item) {
            $curso = $item['curso'];
            if (!isset($porCurso[$curso])) {
                $porCurso[$curso] = [
                    'curso' => $curso,
                    'total_estudiantes' => 0,
                    'asistencia_promedio' => 0,
                    'estudiantes' => [],
                ];
            }
            $porCurso[$curso]['total_estudiantes']++;
            $porCurso[$curso]['estudiantes'][] = $item['estudiante'];
        }

        foreach ($porCurso as &$curso) {
            $asistencias = [];
            foreach ($asistenciaPorEstudiante as $item) {
                if ($item['curso'] === $curso['curso']) {
                    $asistencias[] = $item['porcentaje_asistencia'];
                }
            }
            $curso['asistencia_promedio'] = count($asistencias) > 0 ? round(array_sum($asistencias) / count($asistencias), 2) : 0;
        }

        return array_values($porCurso);
    }

    /**
     * Generar recomendaciones basadas en reportes
     */
    private function generarRecomendaciones(array $asistencia, array $desempeño, array $progreso): array
    {
        $recomendaciones = [];

        // Recomendaciones por asistencia
        if ($asistencia['resumen']['estudiantes_en_alerta'] > 0) {
            $recomendaciones[] = [
                'tipo' => 'asistencia',
                'severidad' => 'alta',
                'mensaje' => "{$asistencia['resumen']['estudiantes_en_alerta']} estudiantes tienen baja asistencia (<80%)",
                'accion' => 'Contactar a estudiantes y ofrecer apoyo',
            ];
        }

        // Recomendaciones por desempeño
        $estudiantesBajoDesempeño = $desempeño['resumen']['estudiantes_bajo_desempeño'] ?? 0;
        if ($estudiantesBajoDesempeño > 0) {
            $recomendaciones[] = [
                'tipo' => 'desempeño',
                'severidad' => 'media',
                'mensaje' => "{$estudiantesBajoDesempeño} estudiantes con calificación promedio < 3.0",
                'accion' => 'Ofrecer tutorías o apoyo académico',
            ];
        }

        // Recomendaciones por progreso
        if ($progreso['resumen']['estudiantes_atrasados'] > 0) {
            $recomendaciones[] = [
                'tipo' => 'progreso',
                'severidad' => 'media',
                'mensaje' => "{$progreso['resumen']['estudiantes_atrasados']} estudiantes van atrasados en el curso",
                'accion' => 'Implementar plan de recuperación',
            ];
        }

        return $recomendaciones;
    }
}

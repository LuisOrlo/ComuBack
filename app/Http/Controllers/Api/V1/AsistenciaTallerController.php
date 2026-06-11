<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAsistenciaTallerRequest;
use App\Http\Requests\UpdateAsistenciaTallerRequest;
use App\Models\AsistenciaTaller;
use App\Models\Taller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsistenciaTallerController extends Controller
{
    /**
     * Lista asistencias de un taller
     */
    public function index(string $taller_id, Request $request): JsonResponse
    {
        Taller::findOrFail($taller_id);

        $query = AsistenciaTaller::where('taller_id', $taller_id);

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha_sesion', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha_sesion', '<=', $request->fecha_fin);
        }

        $asistencias = $query
            ->with('taller')
            ->orderBy('fecha_sesion', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($asistencias);
    }

    /**
     * Registrar asistencia de una sesión
     */
    public function store(StoreAsistenciaTallerRequest $request): JsonResponse
    {
        $taller = Taller::findOrFail($request->taller_id);

        // Validar que la fecha no esté fuera del rango del taller
        $fecha = $request->fecha_sesion;
        if ($fecha < $taller->fecha_inicio || $fecha > $taller->fecha_fin) {
            return response()->json([
                'message' => 'La fecha de la sesión está fuera del rango del taller',
                'fecha_inicio_taller' => $taller->fecha_inicio,
                'fecha_fin_taller' => $taller->fecha_fin,
                'fecha_sesion' => $fecha,
            ], 422);
        }

        // Validar que asistentes no exceda capacidad registrada
        if ($request->asistentes > $request->capacidad_registrada) {
            return response()->json([
                'message' => 'El número de asistentes no puede exceder la capacidad registrada',
                'asistentes' => $request->asistentes,
                'capacidad_registrada' => $request->capacidad_registrada,
            ], 422);
        }

        // Validar que no exista asistencia anterior para esa fecha
        $existe = AsistenciaTaller::where([
            'taller_id' => $request->taller_id,
            'fecha_sesion' => $fecha,
        ])->exists();

        if ($existe) {
            return response()->json([
                'message' => 'Ya existe registro de asistencia para esa fecha',
            ], 422);
        }

        $asistencia = AsistenciaTaller::create($request->validated());

        return response()->json($asistencia->load('taller'), 201);
    }

    /**
     * Ver detalle de una asistencia
     */
    public function show(string $id): JsonResponse
    {
        $asistencia = AsistenciaTaller::with('taller')->findOrFail($id);

        return response()->json($asistencia);
    }

    /**
     * Actualizar asistencia
     */
    public function update(UpdateAsistenciaTallerRequest $request, string $id): JsonResponse
    {
        $asistencia = AsistenciaTaller::findOrFail($id);

        // Validar que asistentes no exceda capacidad registrada
        if ($request->filled('asistentes') && $request->filled('capacidad_registrada')) {
            if ($request->asistentes > $request->capacidad_registrada) {
                return response()->json([
                    'message' => 'El número de asistentes no puede exceder la capacidad registrada',
                ], 422);
            }
        }

        $asistencia->update($request->validated());

        return response()->json($asistencia->load('taller'), 200);
    }

    /**
     * Eliminar asistencia
     */
    public function destroy(string $id): JsonResponse
    {
        $asistencia = AsistenciaTaller::findOrFail($id);
        $asistencia->delete();

        return response()->json(['message' => 'Asistencia eliminada'], 200);
    }

    /**
     * Obtener estadísticas de asistencia de un taller
     */
    public function estadisticas(string $taller_id): JsonResponse
    {
        $taller = Taller::findOrFail($taller_id);

        $asistencias = AsistenciaTaller::where('taller_id', $taller_id)->get();

        $stats = [
            'taller_id' => $taller_id,
            'nombre_taller' => $taller->nombre,
            'total_sesiones' => $asistencias->count(),
            'total_asistencias' => $asistencias->sum('asistentes'),
            'total_capacidad' => $asistencias->sum('capacidad_registrada'),
            'tasa_asistencia_general' => $asistencias->sum('capacidad_registrada') > 0 
                ? round(($asistencias->sum('asistentes') / $asistencias->sum('capacidad_registrada')) * 100, 2)
                : 0,
            'promedio_asistentes_por_sesion' => $asistencias->count() > 0 
                ? round($asistencias->avg('asistentes'), 2)
                : 0,
        ];

        return response()->json($stats);
    }
}

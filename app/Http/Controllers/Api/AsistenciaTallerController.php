<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAsistenciaTallerRequest;
use App\Http\Requests\UpdateAsistenciaTallerRequest;
use App\Models\AsistenciaTaller;
use App\Models\AsistenciaTallerEstudiante;
use App\Models\Taller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * Registrar asistencia de una sesión (con registro por estudiante)
     */
    public function store(StoreAsistenciaTallerRequest $request): JsonResponse
    {
        $taller = Taller::findOrFail($request->taller_id);

        $fecha = \Carbon\Carbon::parse($request->fecha_sesion);

        if (AsistenciaTaller::where(['taller_id' => $request->taller_id, 'fecha_sesion' => $fecha])->exists()) {
            return response()->json(['message' => 'Ya existe registro de asistencia para esa fecha'], 422);
        }

        $data = $request->validated();
        $estudiantes = $data['estudiantes'] ?? [];
        unset($data['estudiantes']);

        $capacidad = $estudiantes ? count($estudiantes) : ($data['capacidad_registrada'] ?? 0);
        $asistentes = $estudiantes ? count(array_filter($estudiantes, fn($e) => ($e['asistio'] ?? false) || ($e['estado'] ?? '') === 'presente' || ($e['estado'] ?? '') === 'tardanza')) : ($data['asistentes'] ?? 0);

        if ($asistentes > $capacidad) {
            return response()->json(['message' => 'El número de asistentes no puede exceder la capacidad registrada'], 422);
        }

        DB::beginTransaction();
        try {
            $data['asistentes'] = $asistentes;
            $data['capacidad_registrada'] = $capacidad;
            $asistencia = AsistenciaTaller::create($data);

            if ($estudiantes) {
                foreach ($estudiantes as $est) {
                    AsistenciaTallerEstudiante::create([
                        'asistencia_taller_id' => $asistencia->id,
                        'inscripcion_taller_id' => $est['inscripcion_taller_id'] ?? null,
                        'participante_externo_id' => $est['participante_externo_id'] ?? null,
                        'asistio' => $est['asistio'] ?? false,
                        'estado' => $est['estado'] ?? ($est['asistio'] ? 'presente' : 'ausente'),
                        'observaciones' => $est['observaciones'] ?? null,
                    ]);
                }
            }

            DB::commit();
            return response()->json($asistencia->load(['taller', 'estudiantes']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al registrar asistencia', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Ver detalle de una asistencia (incluye estudiantes)
     */
    public function show(string $id): JsonResponse
    {
        $asistencia = AsistenciaTaller::with(['taller', 'estudiantes'])->findOrFail($id);

        return response()->json($asistencia);
    }

    /**
     * Actualizar asistencia
     */
    public function update(UpdateAsistenciaTallerRequest $request, string $id): JsonResponse
    {
        $asistencia = AsistenciaTaller::findOrFail($id);

        if ($request->filled('asistentes') && $request->filled('capacidad_registrada')) {
            if ($request->asistentes > $request->capacidad_registrada) {
                return response()->json(['message' => 'El número de asistentes no puede exceder la capacidad registrada'], 422);
            }
        }

        $asistencia->update($request->validated());

        return response()->json($asistencia->load(['taller', 'estudiantes']), 200);
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
     * Listar registros por estudiante de una sesión de asistencia
     */
    public function listEstudiantes(string $taller_id, string $asistencia_id): JsonResponse
    {
        $asistencia = AsistenciaTaller::where('taller_id', $taller_id)->findOrFail($asistencia_id);

        $estudiantes = $asistencia->estudiantes()->with('inscripcionTaller')->get();

        return response()->json([
            'asistencia_id' => $asistencia_id,
            'estudiantes' => $estudiantes,
        ]);
    }

    /**
     * Registrar/actualizar asistencias por estudiante para una sesión
     */
    public function storeEstudiantes(Request $request, string $taller_id, string $asistencia_id): JsonResponse
    {
        $request->validate([
            'estudiantes' => 'required|array',
            'estudiantes.*.inscripcion_taller_id' => 'nullable|required_without:estudiantes.*.participante_externo_id|uuid|exists:inscripciones_taller,id',
            'estudiantes.*.participante_externo_id' => 'nullable|required_without:estudiantes.*.inscripcion_taller_id|uuid|exists:participantes_externos,id',
            'estudiantes.*.asistio' => 'required|boolean',
            'estudiantes.*.estado' => 'nullable|string|in:presente,ausente,tardanza,justificado',
            'estudiantes.*.observaciones' => 'nullable|string',
        ]);

        $asistencia = AsistenciaTaller::where('taller_id', $taller_id)->findOrFail($asistencia_id);

        DB::beginTransaction();
        try {
            foreach ($request->estudiantes as $data) {
                AsistenciaTallerEstudiante::updateOrCreate(
                    [
                        'asistencia_taller_id' => $asistencia_id,
                        'inscripcion_taller_id' => $data['inscripcion_taller_id'] ?? null,
                        'participante_externo_id' => $data['participante_externo_id'] ?? null,
                    ],
                    [
                        'asistio' => $data['asistio'],
                        'estado' => $data['estado'] ?? ($data['asistio'] ? 'presente' : 'ausente'),
                        'observaciones' => $data['observaciones'] ?? null,
                    ]
                );
            }

            // Auto-actualizar el conteo agregado
            $asistentes = $asistencia->estudiantes()->where(function ($q) {
                $q->where('asistio', true)->orWhereIn('estado', ['presente', 'tardanza']);
            })->count();
            $asistencia->update(['asistentes' => $asistentes]);

            DB::commit();
            return response()->json(['mensaje' => 'Asistencia por estudiante registrada correctamente']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['mensaje' => 'Error al registrar asistencia por estudiante', 'error' => $e->getMessage()], 500);
        }
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

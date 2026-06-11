<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTallerRequest;
use App\Http\Requests\UpdateTallerRequest;
use App\Models\Taller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TallerController extends Controller
{
    /**
     * Lista todos los talleres con filtros
     */
    public function index(Request $request): JsonResponse
    {
        $query = Taller::query();

        // Filtros
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->categoria);
        }

        if ($request->filled('profesor_id')) {
            $query->where('profesor_id', $request->profesor_id);
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha_inicio', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha_fin', '<=', $request->fecha_fin);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                  ->orWhere('codigo', 'ilike', "%{$search}%")
                  ->orWhere('descripcion', 'ilike', "%{$search}%");
            });
        }

        $talleres = $query
            ->with(['profesor', 'horarios', 'inscripciones', 'inscripciones_externos', 'asistencias'])
            ->orderBy('fecha_inicio', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($talleres);
    }

    /**
     * Crear nuevo taller
     */
    public function store(StoreTallerRequest $request): JsonResponse
    {
        $taller = Taller::create($request->validated());

        return response()->json($taller->load(['profesor', 'horarios']), 201);
    }

    /**
     * Ver detalle de un taller
     */
    public function show(string $id): JsonResponse
    {
        $taller = Taller::with(['profesor', 'horarios', 'inscripciones', 'inscripciones_externos', 'asistencias'])
            ->findOrFail($id);

        return response()->json($taller);
    }

    /**
     * Actualizar taller
     */
    public function update(UpdateTallerRequest $request, string $id): JsonResponse
    {
        $taller = Taller::findOrFail($id);
        $taller->update($request->validated());

        return response()->json($taller->load(['profesor', 'horarios']), 200);
    }

    /**
     * Eliminar taller
     */
    public function destroy(string $id): JsonResponse
    {
        $taller = Taller::findOrFail($id);
        $taller->delete();

        return response()->json(['message' => 'Taller eliminado'], 200);
    }

    /**
     * Obtener estadísticas del taller
     */
    public function estadisticas(string $id): JsonResponse
    {
        $taller = Taller::findOrFail($id);

        $estadisticas = [
            'id' => $taller->id,
            'nombre' => $taller->nombre,
            'total_inscritos' => $taller->totalInscripciones(),
            'capacidad_disponible' => $taller->capacidadDisponible(),
            'tasa_ocupacion' => (($taller->totalInscripciones() / $taller->capacidad) * 100),
            'tasa_asistencia' => round($taller->tasaAsistencia(), 2) . '%',
            'estado' => $taller->estado,
            'permite_inscripcion' => $taller->permitirInscripcion(),
        ];

        return response()->json($estadisticas);
    }

    /**
     * Cambiar estado masivo de talleres
     */
    public function cambiarEstadoMasivo(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'uuid|exists:academic.talleres,id',
            'estado' => 'required|in:planificado,activo,completado,cancelado',
        ]);

        $count = Taller::whereIn('id', $request->ids)->update(['estado' => $request->estado]);

        return response()->json([
            'message' => "{$count} talleres actualizados",
            'cantidad' => $count,
        ], 200);
    }
}

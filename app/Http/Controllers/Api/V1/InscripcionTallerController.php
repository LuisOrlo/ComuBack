<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInscripcionTallerRequest;
use App\Models\InscripcionTaller;
use App\Models\InscripcionExternoTaller;
use App\Models\Taller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InscripcionTallerController extends Controller
{
    /**
     * Lista inscripciones de un taller (estudiantes)
     */
    public function indexEstudiantes(string $taller_id): JsonResponse
    {
        Taller::findOrFail($taller_id);

        $inscripciones = InscripcionTaller::where('taller_id', $taller_id)
            ->with(['estudiante', 'taller'])
            ->orderBy('fecha_inscripcion', 'desc')
            ->paginate(15);

        return response()->json($inscripciones);
    }

    /**
     * Lista inscripciones de participantes externos
     */
    public function indexExternos(string $taller_id): JsonResponse
    {
        Taller::findOrFail($taller_id);

        $inscripciones = InscripcionExternoTaller::where('taller_id', $taller_id)
            ->with(['participante', 'taller'])
            ->orderBy('fecha_inscripcion', 'desc')
            ->paginate(15);

        return response()->json($inscripciones);
    }

    /**
     * Inscribir estudiante en taller
     */
    public function storeEstudiante(StoreInscripcionTallerRequest $request): JsonResponse
    {
        $taller = Taller::findOrFail($request->taller_id);

        // Validar que la fecha de inicio no haya pasado
        if ($taller->fecha_inicio <= now()->toDateString()) {
            return response()->json([
                'message' => 'No se puede inscribir después de la fecha de inicio del taller',
                'fecha_inicio' => $taller->fecha_inicio,
            ], 422);
        }

        // Validar que no exista inscripción anterior
        $existe = InscripcionTaller::where([
            'taller_id' => $request->taller_id,
            'estudiante_id' => $request->estudiante_id,
        ])->exists();

        if ($existe) {
            return response()->json([
                'message' => 'El estudiante ya está inscrito en este taller',
            ], 422);
        }

        // Validar capacidad
        if ($taller->capacidadDisponible() <= 0) {
            return response()->json([
                'message' => 'El taller está lleno',
                'capacidad' => $taller->capacidad,
                'inscritos' => $taller->totalInscripciones(),
            ], 422);
        }

        $inscripcion = InscripcionTaller::create([
            'taller_id' => $request->taller_id,
            'estudiante_id' => $request->estudiante_id,
            'fecha_inscripcion' => now()->toDateString(),
            'estado' => 'inscrito',
        ]);

        return response()->json($inscripcion->load(['estudiante', 'taller']), 201);
    }

    /**
     * Inscribir participante externo en taller
     */
    public function storeExterno(Request $request, string $taller_id): JsonResponse
    {
        $request->validate([
            'participante_externo_id' => 'required|uuid|exists:academic.participantes_externos,id',
        ]);

        $taller = Taller::findOrFail($taller_id);

        // Validar que la fecha de inicio no haya pasado
        if ($taller->fecha_inicio <= now()->toDateString()) {
            return response()->json([
                'message' => 'No se puede inscribir después de la fecha de inicio del taller',
            ], 422);
        }

        // Validar que no exista inscripción anterior
        $existe = InscripcionExternoTaller::where([
            'taller_id' => $taller_id,
            'participante_externo_id' => $request->participante_externo_id,
        ])->exists();

        if ($existe) {
            return response()->json([
                'message' => 'El participante ya está inscrito en este taller',
            ], 422);
        }

        // Validar capacidad
        if ($taller->capacidadDisponible() <= 0) {
            return response()->json([
                'message' => 'El taller está lleno',
            ], 422);
        }

        $inscripcion = InscripcionExternoTaller::create([
            'taller_id' => $taller_id,
            'participante_externo_id' => $request->participante_externo_id,
            'fecha_inscripcion' => now()->toDateString(),
            'estado' => 'inscrito',
        ]);

        return response()->json($inscripcion->load(['participante', 'taller']), 201);
    }

    /**
     * Ver detalle de una inscripción de estudiante
     */
    public function showEstudiante(string $id): JsonResponse
    {
        $inscripcion = InscripcionTaller::with(['estudiante', 'taller'])->findOrFail($id);

        return response()->json($inscripcion);
    }

    /**
     * Ver detalle de una inscripción de externo
     */
    public function showExterno(string $id): JsonResponse
    {
        $inscripcion = InscripcionExternoTaller::with(['participante', 'taller'])->findOrFail($id);

        return response()->json($inscripcion);
    }

    /**
     * Cambiar estado de inscripción de estudiante
     */
    public function updateEstadoEstudiante(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|in:inscrito,completado,retirado',
        ]);

        $inscripcion = InscripcionTaller::findOrFail($id);
        $inscripcion->update(['estado' => $request->estado]);

        return response()->json($inscripcion->load(['estudiante', 'taller']), 200);
    }

    /**
     * Cambiar estado de inscripción de externo
     */
    public function updateEstadoExterno(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|in:inscrito,completado,retirado',
        ]);

        $inscripcion = InscripcionExternoTaller::findOrFail($id);
        $inscripcion->update(['estado' => $request->estado]);

        return response()->json($inscripcion->load(['participante', 'taller']), 200);
    }

    /**
     * Eliminar inscripción de estudiante
     */
    public function destroyEstudiante(string $id): JsonResponse
    {
        $inscripcion = InscripcionTaller::findOrFail($id);
        $inscripcion->delete();

        return response()->json(['message' => 'Inscripción eliminada'], 200);
    }

    /**
     * Eliminar inscripción de externo
     */
    public function destroyExterno(string $id): JsonResponse
    {
        $inscripcion = InscripcionExternoTaller::findOrFail($id);
        $inscripcion->delete();

        return response()->json(['message' => 'Inscripción eliminada'], 200);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHorarioTallerRequest;
use App\Http\Requests\UpdateHorarioTallerRequest;
use App\Models\HorarioTaller;
use App\Models\Taller;
use App\Services\WorkshopScheduleConflictService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HorarioTallerController extends Controller
{
    private WorkshopScheduleConflictService $scheduleService;

    public function __construct(WorkshopScheduleConflictService $scheduleService)
    {
        $this->scheduleService = $scheduleService;
    }

    /**
     * Lista horarios de un taller
     */
    public function index(string $taller_id): JsonResponse
    {
        Taller::findOrFail($taller_id);

        $horarios = HorarioTaller::where('taller_id', $taller_id)
            ->with('taller')
            ->orderBy('dia_semana')
            ->orderBy('hora_inicio')
            ->get();

        return response()->json($horarios);
    }

    /**
     * Crear nuevo horario para taller
     */
    public function store(StoreHorarioTallerRequest $request): JsonResponse
    {
        // Validar que no exista conflicto de horarios
        $validacion = $this->scheduleService->validarSinCruces(
            (string) $request->dia_semana,
            $request->hora_inicio,
            $request->hora_fin,
            $request->taller_id
        );

        if (!$validacion['valido']) {
            return response()->json([
                'message' => 'Conflicto de horario detectado',
                'errores' => $validacion['errores'],
                'conflictos' => $validacion['conflictos'],
            ], 422);
        }

        // Validar que capacidad no exceda capacidad del taller
        $taller = Taller::findOrFail($request->taller_id);
        if ($request->capacidad > $taller->capacidad) {
            return response()->json([
                'message' => 'La capacidad del horario no puede exceder la del taller',
                'capacidad_taller' => $taller->capacidad,
                'capacidad_solicitada' => $request->capacidad,
            ], 422);
        }

        $horario = HorarioTaller::create($request->validated());

        return response()->json($horario->load('taller'), 201);
    }

    /**
     * Ver detalle de un horario
     */
    public function show(string $id): JsonResponse
    {
        $horario = HorarioTaller::with('taller')->findOrFail($id);

        return response()->json($horario);
    }

    /**
     * Actualizar horario
     */
    public function update(UpdateHorarioTallerRequest $request, string $id): JsonResponse
    {
        $horario = HorarioTaller::findOrFail($id);

        // Si se cambian horas, validar conflictos
        if ($request->filled(['dia_semana', 'hora_inicio', 'hora_fin'])) {
            $validacion = $this->scheduleService->validarSinCruces(
                (string) $request->dia_semana ?? $horario->dia_semana,
                $request->hora_inicio ?? $horario->hora_inicio,
                $request->hora_fin ?? $horario->hora_fin,
                $horario->taller_id
            );

            if (!$validacion['valido']) {
                return response()->json([
                    'message' => 'Conflicto de horario detectado',
                    'errores' => $validacion['errores'],
                ], 422);
            }
        }

        $horario->update($request->validated());

        return response()->json($horario->load('taller'), 200);
    }

    /**
     * Eliminar horario
     */
    public function destroy(string $id): JsonResponse
    {
        $horario = HorarioTaller::findOrFail($id);
        $horario->delete();

        return response()->json(['message' => 'Horario eliminado'], 200);
    }
}

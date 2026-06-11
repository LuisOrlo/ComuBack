<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Horario;
use App\Models\HorarioDia;
use App\Http\Requests\StoreHorarioRequest;
use App\Http\Requests\UpdateHorarioRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HorarioController extends Controller
{
    public function index(Request $request)
    {
        $query = Horario::query();

        if ($request->has('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        if ($request->has('activos') && $request->activos == 'true') {
            $query->activos();
        }

        if ($request->has('buscar')) {
            $query->buscar($request->buscar);
        }

        $perPage = $request->get('per_page', 15);
        $horarios = $query->with('diasSemana')->paginate($perPage);

        return response()->json([
            'data' => $horarios->items(),
            'meta' => [
                'total' => $horarios->total(),
                'per_page' => $horarios->perPage(),
                'current_page' => $horarios->currentPage(),
                'last_page' => $horarios->lastPage(),
            ]
        ]);
    }

    public function store(StoreHorarioRequest $request)
    {
        $validated = $request->validated();
        $dias = $validated['dias_semana'];
        unset($validated['dias_semana']);

        $horario = Horario::create($validated);

        foreach ($dias as $dia) {
            HorarioDia::create([
                'horario_id' => $horario->id,
                'dia_semana' => $dia,
            ]);
        }

        return response()->json(['data' => $horario->load('diasSemana'), 'message' => 'Creado exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $horario = Horario::with(['diasSemana', 'cursoAbierto'])->findOrFail($id);
        return response()->json(['data' => $horario]);
    }

    public function update(UpdateHorarioRequest $request, $id)
    {
        $horario = Horario::findOrFail($id);
        $validated = $request->validated();

        if (isset($validated['dias_semana'])) {
            $dias = $validated['dias_semana'];
            unset($validated['dias_semana']);

            $horario->diasSemana()->delete();
            foreach ($dias as $dia) {
                HorarioDia::create(['horario_id' => $horario->id, 'dia_semana' => $dia]);
            }
        }

        $horario->update($validated);
        return response()->json(['data' => $horario->load('diasSemana'), 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        Horario::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function matriculas($id)
    {
        $horario = Horario::findOrFail($id);
        $matriculas = $horario->matriculas()->with('estudiante')->paginate(15);
        return response()->json(['data' => $matriculas->items(), 'meta' => ['total' => $matriculas->total()]]);
    }

    public function descripcion($id)
    {
        $horario = Horario::findOrFail($id);
        return response()->json([
            'data' => [
                'id' => $horario->id,
                'nombre' => $horario->nombre_referencial,
                'descripcion' => $horario->obtenerDescripcion(),
                'dias' => $horario->obtenerDiasNombres(),
                'hora_inicio' => $horario->hora_inicio,
                'hora_fin' => $horario->hora_fin,
                'matriculas_inscritas' => $horario->obtenerCountMatriculas(),
            ]
        ]);
    }
}

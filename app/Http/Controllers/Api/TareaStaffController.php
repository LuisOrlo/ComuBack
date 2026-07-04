<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTareaStaffRequest;
use App\Http\Requests\UpdateTareaStaffRequest;
use App\Http\Requests\CambiarEstadoTareaRequest;
use App\Models\Persona;
use App\Models\TareaStaff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TareaStaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TareaStaff::with('persona:id,nombres,apellidos,tipo');

        if ($request->filled('titulo')) {
            $query->where('titulo', 'ilike', '%' . $request->titulo . '%');
        }

        if ($request->filled('persona_id')) {
            $query->where('persona_id', $request->persona_id);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['titulo', 'fecha_inicio', 'fecha_fin', 'estado', 'created_at'];

        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->get('per_page', 15), 50);
        $tareas = $query->paginate($perPage);

        $totales = [
            'total' => TareaStaff::count(),
            'pendiente' => TareaStaff::where('estado', 'pendiente')->count(),
            'en_progreso' => TareaStaff::where('estado', 'en_progreso')->count(),
            'completada' => TareaStaff::where('estado', 'completada')->count(),
        ];

        return response()->json([
            'tareas' => $tareas->items(),
            'meta' => [
                'current_page' => $tareas->currentPage(),
                'last_page' => $tareas->lastPage(),
                'per_page' => $tareas->perPage(),
                'total' => $tareas->total(),
            ],
            'totales' => $totales,
        ]);
    }

    public function store(StoreTareaStaffRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->id();

        $tarea = TareaStaff::create($data);
        $tarea->load('persona:id,nombres,apellidos,tipo');

        return response()->json(['tarea' => $tarea], 201);
    }

    public function update(UpdateTareaStaffRequest $request, string $id): JsonResponse
    {
        $tarea = TareaStaff::findOrFail($id);
        $tarea->update($request->validated());
        $tarea->load('persona:id,nombres,apellidos,tipo');

        return response()->json(['tarea' => $tarea]);
    }

    public function cambiarEstado(CambiarEstadoTareaRequest $request, string $id): JsonResponse
    {
        $tarea = TareaStaff::findOrFail($id);
        $tarea->update(['estado' => $request->estado]);
        $tarea->load('persona:id,nombres,apellidos,tipo');

        return response()->json(['tarea' => $tarea]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tarea = TareaStaff::findOrFail($id);
        $tarea->delete();

        return response()->json(['mensaje' => 'Tarea eliminada correctamente']);
    }

    public function staffDisponible(): JsonResponse
    {
        $staff = Persona::whereIn('tipo', ['staff', 'secretaria', 'admin'])
            ->where('es_activo', true)
            ->select('id', 'nombres', 'apellidos', 'tipo')
            ->orderBy('nombres')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'nombre_completo' => trim("{$p->nombres} {$p->apellidos}"),
                'iniciales' => mb_substr($p->nombres, 0, 1) . mb_substr($p->apellidos, 0, 1),
                'tipo' => $p->tipo,
            ]);

        return response()->json(['staff' => $staff]);
    }
}

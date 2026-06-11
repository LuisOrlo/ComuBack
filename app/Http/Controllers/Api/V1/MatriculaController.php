<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use App\Http\Requests\StoreMatriculaRequest;
use App\Http\Requests\UpdateMatriculaRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MatriculaController extends Controller
{
    public function index(Request $request)
    {
        $query = Matricula::query();

        if ($request->has('estudiante_id')) {
            $query->where('estudiante_id', $request->estudiante_id);
        }

        if ($request->has('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('activas') && $request->activas == 'true') {
            $query->activas();
        }

        $perPage = $request->get('per_page', 15);
        $matriculas = $query->with(['estudiante', 'cursoAbierto'])->paginate($perPage);

        return response()->json([
            'data' => $matriculas->items(),
            'meta' => [
                'total' => $matriculas->total(),
                'per_page' => $matriculas->perPage(),
                'current_page' => $matriculas->currentPage(),
                'last_page' => $matriculas->lastPage(),
            ]
        ]);
    }

    public function store(StoreMatriculaRequest $request)
    {
        $matricula = Matricula::create($request->validated());
        return response()->json(['data' => $matricula, 'message' => 'Creado exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto', 'horario', 'notas'])->findOrFail($id);
        return response()->json(['data' => $matricula]);
    }

    public function update(UpdateMatriculaRequest $request, $id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto'])->findOrFail($id);
        $matricula->update($request->validated());
        return response()->json(['data' => $matricula, 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        Matricula::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function notas($id)
    {
        $notas = Matricula::findOrFail($id)->notas()->with('modulo')->paginate(15);
        return response()->json(['data' => $notas->items(), 'meta' => ['total' => $notas->total()]]);
    }

    public function calificaciones($id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto.modulos', 'notas.modulo'])->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $matricula->id,
                'estudiante' => $matricula->estudiante ? $matricula->estudiante->nombre : 'N/A',
                'curso' => $matricula->cursoAbierto ? $matricula->cursoAbierto->nombre_instancia : 'N/A',
                'promedio_simple' => $matricula->calcularPromedio(),
                'promedio_ponderado' => $matricula->calcularPromedioPonderado(),
                'total_notas_registradas' => $matricula->notas()->whereNotNull('calificacion')->count(),
                'total_modulos' => $matricula->cursoAbierto ? $matricula->cursoAbierto->modulos()->count() : 0,
                'todas_notas_registradas' => $matricula->tieneTotalNotasRegistradas(),
                'estado' => $matricula->obtenerDescripcionEstado(),
            ]
        ]);
    }

    public function cambiosHorario($id)
    {
        $cambios = Matricula::findOrFail($id)->cambiosHorario()->with(['cursoAbiertoAntiguo', 'cursoAbiertoNuevo'])->paginate(15);
        return response()->json(['data' => $cambios->items(), 'meta' => ['total' => $cambios->total()]]);
    }
}

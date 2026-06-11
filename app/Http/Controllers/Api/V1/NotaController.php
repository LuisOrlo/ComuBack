<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Nota;
use App\Http\Requests\StoreNotaRequest;
use App\Http\Requests\UpdateNotaRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotaController extends Controller
{
    public function index(Request $request)
    {
        $query = Nota::query();

        if ($request->has('matricula_id')) {
            $query->where('matricula_id', $request->matricula_id);
        }

        if ($request->has('modulo_id')) {
            $query->where('modulo_id', $request->modulo_id);
        }

        if ($request->has('estado') && $request->estado == 'registradas') {
            $query->registradas();
        }

        if ($request->has('estado') && $request->estado == 'pendientes') {
            $query->pendientes();
        }

        if ($request->has('aprobadas') && $request->aprobadas == 'true') {
            $query->aprobadas();
        }

        $perPage = $request->get('per_page', 15);
        $notas = $query->with(['matricula', 'modulo'])->paginate($perPage);

        return response()->json([
            'data' => $notas->items(),
            'meta' => [
                'total' => $notas->total(),
                'per_page' => $notas->perPage(),
                'current_page' => $notas->currentPage(),
                'last_page' => $notas->lastPage(),
            ]
        ]);
    }

    public function store(StoreNotaRequest $request)
    {
        $nota = Nota::create($request->validated());
        return response()->json(['data' => $nota, 'message' => 'Creado exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $nota = Nota::with(['matricula', 'modulo'])->findOrFail($id);
        return response()->json(['data' => $nota]);
    }

    public function update(UpdateNotaRequest $request, $id)
    {
        $nota = Nota::findOrFail($id);
        $nota->update($request->validated());
        return response()->json(['data' => $nota, 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        Nota::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function descriptiva($id)
    {
        $nota = Nota::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $nota->id,
                'calificacion' => $nota->obtenerRepresentacionVisual(),
                'descriptiva' => $nota->obtenerCalificacionDescriptiva(),
                'estado' => $nota->obtenerDescripcionEstado(),
                'es_aprobada' => $nota->estaAprobada(),
                'es_reprobada' => $nota->estaReprobada(),
            ]
        ]);
    }
}

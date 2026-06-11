<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Modulo;
use App\Http\Requests\StoreModuloRequest;
use App\Http\Requests\UpdateModuloRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ModuloController extends Controller
{
    public function index(Request $request)
    {
        $query = Modulo::query();

        if ($request->has('catalogo_curso_id')) {
            $query->where('catalogo_curso_id', $request->catalogo_curso_id);
        }

        if ($request->has('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        if ($request->has('tipo') && $request->tipo == 'predeterminado') {
            $query->delCatalogo();
        }

        if ($request->has('tipo') && $request->tipo == 'personalizado') {
            $query->personalizados();
        }

        $perPage = $request->get('per_page', 15);
        $modulos = $query->paginate($perPage);

        return response()->json([
            'data' => $modulos->items(),
            'meta' => [
                'total' => $modulos->total(),
                'per_page' => $modulos->perPage(),
                'current_page' => $modulos->currentPage(),
                'last_page' => $modulos->lastPage(),
            ]
        ]);
    }

    public function store(StoreModuloRequest $request)
    {
        $modulo = Modulo::create($request->validated());
        return response()->json(['data' => $modulo, 'message' => 'Creado exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $modulo = Modulo::with(['notas', 'catalogo', 'cursoAbierto'])->findOrFail($id);
        return response()->json(['data' => $modulo]);
    }

    public function update(UpdateModuloRequest $request, $id)
    {
        $modulo = Modulo::findOrFail($id);
        $modulo->update($request->validated());
        return response()->json(['data' => $modulo, 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        Modulo::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function notas($id)
    {
        $modulo = Modulo::findOrFail($id);
        $notas = $modulo->notas()->with('matricula')->paginate(15);
        return response()->json(['data' => $notas->items(), 'meta' => ['total' => $notas->total()]]);
    }

    public function estadisticas($id)
    {
        $modulo = Modulo::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $modulo->id,
                'nombre' => $modulo->nombre,
                'tipo' => $modulo->esPredeterminado() ? 'predeterminado' : 'personalizado',
                'duracion_semanas' => $modulo->obtenerDuracionSemanas(),
                'ponderacion' => $modulo->obtenerPonderacionPorcentaje(),
                'periodo' => $modulo->obtenerPeriodo(),
                'notas_registradas' => $modulo->obtenerCountNotas(),
            ]
        ]);
    }
}

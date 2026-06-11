<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Services\Aula;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AulaController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 15);
        $aulas = Aula::orderBy('nombre')->paginate($perPage);
        return response()->json([
            'data' => $aulas->items(),
            'meta' => [
                'current_page' => $aulas->currentPage(),
                'last_page' => $aulas->lastPage(),
                'per_page' => $aulas->perPage(),
                'total' => $aulas->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:100|unique:aulas,nombre',
            'capacidad' => 'required|integer|min:1',
            'precio_hora' => 'required|numeric|min:0',
            'caracteristicas' => 'nullable|string'
        ]);

        $aula = Aula::create($validated);

        return response()->json([
            'message' => 'Aula creada exitosamente.',
            'data' => $aula
        ], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $aula = Aula::findOrFail($id);
        return response()->json(['data' => $aula]);
    }

    public function update(Request $request, $id)
    {
        $aula = Aula::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|string|max:100|unique:aulas,nombre,' . $id,
            'capacidad' => 'sometimes|integer|min:1',
            'precio_hora' => 'sometimes|numeric|min:0',
            'caracteristicas' => 'nullable|string'
        ]);

        $aula->update($validated);

        return response()->json([
            'message' => 'Aula actualizada exitosamente.',
            'data' => $aula
        ]);
    }

    public function destroy($id)
    {
        $aula = Aula::findOrFail($id);
        $aula->delete();

        return response()->json([
            'message' => 'Aula eliminada exitosamente.'
        ]);
    }
}

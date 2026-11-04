<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Services\Set;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SetController extends Controller
{
    public function index(): JsonResponse
    {
        $sets = Set::orderBy('nombre')->get();
        return response()->json(['data' => $sets]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:150',
                'regex:/^[\pL\pN\s\-_.,()]+$/u',
            ],
            'precio_hora' => 'required|numeric|min:0|max:99999.99',
            'descripcion' => 'nullable|string|max:1000',
            'activo' => 'boolean',
        ], [
            'nombre.regex' => 'El nombre del set solo puede contener letras, números y signos de puntuación básicos.',
            'precio_hora.numeric' => 'El precio por hora debe ser un valor numérico válido.',
        ]);

        $set = Set::create($validated);

        return response()->json([
            'message' => 'Set creado exitosamente.',
            'data' => $set,
        ], Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $set = Set::withCount('reservas')->findOrFail($id);
        return response()->json(['data' => $set]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $set = Set::findOrFail($id);

        $validated = $request->validate([
            'nombre' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                'regex:/^[\pL\pN\s\-_.,()]+$/u',
            ],
            'precio_hora' => 'sometimes|required|numeric|min:0|max:99999.99',
            'descripcion' => 'nullable|string|max:1000',
            'activo' => 'boolean',
        ], [
            'nombre.regex' => 'El nombre del set solo puede contener letras, números y signos de puntuación básicos.',
            'precio_hora.numeric' => 'El precio por hora debe ser un valor numérico válido.',
        ]);

        $set->update($validated);

        return response()->json([
            'message' => 'Set actualizado exitosamente.',
            'data' => $set,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $set = Set::findOrFail($id);

        if ($set->reservas()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el set porque tiene reservas registradas. Puedes desactivarlo.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $set->delete();

        return response()->json([
            'message' => 'Set eliminado exitosamente.',
        ]);
    }
}

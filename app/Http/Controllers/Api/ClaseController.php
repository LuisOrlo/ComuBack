<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ClaseController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $clase = Clase::with(['modulo', 'instructor'])->findOrFail($id);
        return response()->json(['data' => $clase]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $clase = Clase::findOrFail($id);

        $validated = $request->validate([
            'fecha_clase' => 'nullable|date',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fin' => 'nullable|date_format:H:i|after:hora_inicio',
            'instructor_id' => 'nullable|uuid|exists:personas,id',
        ]);

        $clase->update($validated);

        return response()->json(['data' => $clase->fresh(), 'mensaje' => 'Clase actualizada']);
    }

    public function destroy(string $id): JsonResponse
    {
        $clase = Clase::findOrFail($id);
        $clase->delete();

        return response()->json(['mensaje' => 'Clase eliminada']);
    }
}

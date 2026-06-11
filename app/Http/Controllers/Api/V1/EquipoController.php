<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Services\Equipo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EquipoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Equipo::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                  ->orWhere('descripcion', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $equipos = $query->orderBy('nombre')->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => $equipos->items(),
            'meta' => [
                'current_page' => $equipos->currentPage(),
                'last_page' => $equipos->lastPage(),
                'per_page' => $equipos->perPage(),
                'total' => $equipos->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:200',
            'descripcion' => 'nullable|string',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'precio_diario' => 'required|numeric|min:0',
            'estado' => 'nullable|in:disponible,alquilado,mantenimiento',
        ]);

        if ($request->hasFile('foto')) {
            $path = $request->file('foto')->store('equipos', 'public');
            $validated['foto_url'] = Storage::url($path);
        }

        $equipo = Equipo::create($validated);

        return response()->json(['data' => $equipo], 201);
    }

    public function show(string $id): JsonResponse
    {
        $equipo = Equipo::with('alquileres')->findOrFail($id);
        return response()->json(['data' => $equipo]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $equipo = Equipo::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|string|max:200',
            'descripcion' => 'nullable|string',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'precio_diario' => 'sometimes|numeric|min:0',
            'estado' => 'nullable|in:disponible,alquilado,mantenimiento',
        ]);

        if ($request->hasFile('foto')) {
            if ($equipo->foto_url) {
                $oldPath = str_replace('/storage/', '', $equipo->foto_url);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('foto')->store('equipos', 'public');
            $validated['foto_url'] = Storage::url($path);
        }

        $equipo->update($validated);

        return response()->json(['data' => $equipo]);
    }

    public function destroy(string $id): JsonResponse
    {
        $equipo = Equipo::findOrFail($id);
        $equipo->delete();

        return response()->json(['message' => 'Equipo eliminado correctamente']);
    }
}

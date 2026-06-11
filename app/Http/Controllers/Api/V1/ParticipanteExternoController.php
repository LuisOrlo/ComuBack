<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParticipanteExternoRequest;
use App\Http\Requests\UpdateParticipanteExternoRequest;
use App\Models\ParticipanteExterno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipanteExternoController extends Controller
{
    /**
     * Lista participantes externos
     */
    public function index(Request $request): JsonResponse
    {
        $query = ParticipanteExterno::query();

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->filled('institucion')) {
            $query->where('institucion', 'ilike', "%{$request->institucion}%");
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('telefono', 'like', "%{$search}%");
            });
        }

        $participantes = $query
            ->with('inscripciones')
            ->orderBy('nombre')
            ->paginate($request->per_page ?? 15);

        return response()->json($participantes);
    }

    /**
     * Crear participante externo
     */
    public function store(StoreParticipanteExternoRequest $request): JsonResponse
    {
        $participante = ParticipanteExterno::create($request->validated());

        return response()->json($participante, 201);
    }

    /**
     * Ver detalle de participante externo
     */
    public function show(string $id): JsonResponse
    {
        $participante = ParticipanteExterno::with('inscripciones.taller')->findOrFail($id);

        return response()->json($participante);
    }

    /**
     * Actualizar participante externo
     */
    public function update(UpdateParticipanteExternoRequest $request, string $id): JsonResponse
    {
        $participante = ParticipanteExterno::findOrFail($id);
        $participante->update($request->validated());

        return response()->json($participante, 200);
    }

    /**
     * Eliminar participante externo
     */
    public function destroy(string $id): JsonResponse
    {
        $participante = ParticipanteExterno::findOrFail($id);
        $participante->delete();

        return response()->json(['message' => 'Participante eliminado'], 200);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClienteExterno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClienteExternoController extends Controller
{
    /**
     * Lista clientes externos con busqueda
     */
    public function index(Request $request): JsonResponse
    {
        $query = ClienteExterno::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombres', 'ilike', "%{$search}%")
                  ->orWhere('apellidos', 'ilike', "%{$search}%")
                  ->orWhere('cedula', 'ilike', "%{$search}%")
                  ->orWhere('correo', 'ilike', "%{$search}%")
                  ->orWhere('celular', 'ilike', "%{$search}%");
            });
        }

        $clientes = $query->orderBy('nombres')->paginate($request->per_page ?? 15);

        return response()->json($clientes);
    }

    /**
     * Crear cliente externo
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombres' => 'required|string|max:100',
            'apellidos' => 'nullable|string|max:100',
            'cedula' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:150',
            'celular' => 'nullable|string|max:20',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'observaciones' => 'nullable|string',
        ]);

        $cliente = ClienteExterno::create($validated);

        return response()->json(['data' => $cliente], 201);
    }

    /**
     * Ver detalle de cliente externo
     */
    public function show(string $id): JsonResponse
    {
        $cliente = ClienteExterno::findOrFail($id);
        return response()->json(['data' => $cliente]);
    }

    /**
     * Buscar por cedula
     */
    public function buscarCedula(Request $request): JsonResponse
    {
        $request->validate(['cedula' => 'required|string|max:20']);

        $cliente = ClienteExterno::where('cedula', $request->cedula)->first();

        if (!$cliente) {
            return response()->json(['data' => null], 200);
        }

        return response()->json(['data' => $cliente]);
    }
}

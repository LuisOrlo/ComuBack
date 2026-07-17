<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\Services\AlquilerEquipo;
use App\Models\Services\Equipo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class AlquilerEquipoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        AlquilerEquipo::actualizarVencidos();

        $query = AlquilerEquipo::with(['equipo', 'persona', 'clienteExterno']);

        if ($request->filled('equipo_id')) {
            $query->where('equipo_id', $request->equipo_id);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('equipo', function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%");
            });
        }

        $alquileres = $query->orderBy('fecha_entrega', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => $alquileres->items(),
            'meta' => [
                'current_page' => $alquileres->currentPage(),
                'last_page' => $alquileres->lastPage(),
                'per_page' => $alquileres->perPage(),
                'total' => $alquileres->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'equipo_id' => 'required|uuid|exists:equipos,id',
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'fecha_entrega' => 'required|date',
            'fecha_devolucion_esperada' => 'required|date|after:fecha_entrega',
            'foto_salida' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'observaciones' => 'nullable|string',
            'precio_total' => 'required|numeric|min:0',
        ]);

        if (empty($validated['persona_id']) && empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Debe especificar un responsable'], 422);
        }

        if (!empty($validated['persona_id']) && !empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable'], 422);
        }

        $equipo = Equipo::findOrFail($validated['equipo_id']);
        if ($equipo->estado !== 'disponible') {
            return response()->json(['message' => 'El equipo no está disponible para alquiler'], 422);
        }

        if ($request->hasFile('foto_salida')) {
            $path = $request->file('foto_salida')->store('alquileres', 's3');
            $validated['foto_salida_url'] = Storage::disk('s3')->url($path);
        }

        $validated['estado'] = 'pendiente';

        $alquiler = AlquilerEquipo::create($validated);

        CuentaPorCobrar::create([
            'alquiler_equipo_id' => $alquiler->id,
            'monto_total' => $validated['precio_total'],
            'monto_abonado' => 0,
            'estado' => 'pendiente',
            'es_legacy' => false,
        ]);

        $equipo->update(['estado' => 'alquilado']);

        return response()->json([
            'message' => 'Alquiler registrado exitosamente',
            'data' => $alquiler->load(['equipo', 'persona', 'clienteExterno']),
        ], Response::HTTP_CREATED);
    }

    public function entregar(string $id): JsonResponse
    {
        $alquiler = AlquilerEquipo::findOrFail($id);

        if ($alquiler->estado !== 'pendiente') {
            return response()->json(['message' => 'Solo se pueden entregar alquileres en estado pendiente'], 422);
        }

        $alquiler->update([
            'estado' => 'entregado',
            'fecha_entrega' => now(),
        ]);

        $alquiler->equipo()->update(['estado' => 'disponible']);

        return response()->json([
            'message' => 'Equipo marcado como entregado',
            'data' => $alquiler->fresh()->load(['equipo', 'persona', 'clienteExterno']),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $alquiler = AlquilerEquipo::with(['equipo', 'persona', 'clienteExterno'])->findOrFail($id);
        return response()->json(['data' => $alquiler]);
    }

    public function devolver(Request $request, string $id): JsonResponse
    {
        $alquiler = AlquilerEquipo::findOrFail($id);

        if ($alquiler->estado !== 'activo' && $alquiler->estado !== 'vencido' && $alquiler->estado !== 'entregado') {
            return response()->json(['message' => 'Este alquiler ya fue devuelto'], 422);
        }

        $validated = $request->validate([
            'foto_retorno' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'observaciones' => 'nullable|string',
        ]);

        $updateData = [
            'fecha_recepcion' => now(),
            'observaciones' => $validated['observaciones'] ?? $alquiler->observaciones,
            'estado' => 'devuelto',
        ];

        if ($request->hasFile('foto_retorno')) {
            $path = $request->file('foto_retorno')->store('alquileres', 's3');
            $updateData['foto_retorno_url'] = Storage::disk('s3')->url($path);
        }

        $alquiler->update($updateData);

        $alquiler->equipo()->update(['estado' => 'disponible']);

        return response()->json([
            'message' => 'Equipo devuelto correctamente',
            'data' => $alquiler->load(['equipo', 'persona', 'clienteExterno']),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $alquiler = AlquilerEquipo::findOrFail($id);

        if ($alquiler->estado === 'activo' || $alquiler->estado === 'vencido' || $alquiler->estado === 'entregado' || $alquiler->estado === 'pendiente') {
            $alquiler->equipo()->update(['estado' => 'disponible']);
        }

        $alquiler->delete();

        return response()->json(['message' => 'Alquiler eliminado correctamente']);
    }

    public function vencidos(): JsonResponse
    {
        AlquilerEquipo::actualizarVencidos();

        $vencidos = AlquilerEquipo::with(['equipo', 'persona', 'clienteExterno'])
            ->where('estado', 'activo')
            ->where('fecha_devolucion_esperada', '<', now())
            ->get();

        return response()->json(['data' => $vencidos]);
    }
}

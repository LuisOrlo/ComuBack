<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\Persona;
use App\Models\Services\TrabajoEdicion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrabajoEdicionController extends Controller
{
    public function index(Request $request)
    {
        $query = TrabajoEdicion::query();

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('titulo', 'ilike', "%{$search}%")
                  ->orWhere('descripcion', 'ilike', "%{$search}%");
            });
        }

        $trabajos = $query->with('reservaPodcast', 'cliente', 'clienteExterno')->orderBy('fecha_limite')
            ->paginate($request->get('per_page', 15));

        $allEditorIds = $trabajos->pluck('editor_ids')->flatten()->unique()->filter()->values()->toArray();
        $personasMap = collect();
        if (!empty($allEditorIds)) {
            $personasMap = Persona::whereIn('id', $allEditorIds)
                ->get(['id', 'nombres', 'apellidos'])
                ->keyBy('id');
        }

        return response()->json([
            'data' => $trabajos->map(function ($t) use ($personasMap) {
                $editoresIds = $t->editor_ids ?? [];
                $editores = collect($editoresIds)
                    ->map(fn($id) => $personasMap->get($id))
                    ->filter()
                    ->values()
                    ->toArray();

                return [
                    'id' => $t->id,
                    'titulo' => $t->titulo,
                    'descripcion' => $t->descripcion,
                    'fecha_recibo' => $t->fecha_recibo?->format('Y-m-d'),
                    'fecha_limite' => $t->fecha_limite?->format('Y-m-d'),
                    'fecha_entrega' => $t->fecha_entrega?->format('Y-m-d'),
                    'estado' => $t->estado,
                    'editor_ids' => $editoresIds,
                    'editores' => $editores,
                    'persona_id' => $t->persona_id,
                    'cliente_externo_id' => $t->cliente_externo_id,
                    'cliente' => $t->relationLoaded('cliente') && $t->cliente
                        ? ['id' => $t->cliente->id, 'nombres' => $t->cliente->nombres, 'apellidos' => $t->cliente->apellidos]
                        : null,
                    'cliente_externo' => $t->relationLoaded('clienteExterno') && $t->clienteExterno
                        ? ['id' => $t->clienteExterno->id, 'nombres' => $t->clienteExterno->nombres, 'apellidos' => $t->clienteExterno->apellidos, 'cedula' => $t->clienteExterno->cedula, 'correo' => $t->clienteExterno->correo]
                        : null,
                    'reserva_podcast_id' => $t->reserva_podcast_id,
                    'precio_cobrado' => $t->precio_cobrado,
                    'cobro_registrado' => $t->cobro_registrado,
                    'notas' => $t->notas,
                    'created_at' => $t->created_at?->toISOString(),
                ];
            }),
            'meta' => [
                'current_page' => $trabajos->currentPage(),
                'last_page' => $trabajos->lastPage(),
                'per_page' => $trabajos->perPage(),
                'total' => $trabajos->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'titulo' => 'required|string|max:300',
            'descripcion' => 'nullable|string',
            'fecha_recibo' => 'required|date',
            'fecha_limite' => 'required|date|after_or_equal:fecha_recibo',
            'estado' => 'nullable|string|in:recibido,en_proceso,revision,entregado',
            'editor_ids' => 'nullable|array',
            'editor_ids.*' => 'uuid|exists:personas,id',
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'reserva_podcast_id' => 'nullable|uuid|exists:reservas_podcast,id',
            'precio_cobrado' => 'nullable|numeric|min:0',
            'cobro_registrado' => 'boolean',
            'notas' => 'nullable|string',
        ]);

        if (empty($validated['persona_id']) && empty($validated['cliente_externo_id'])) {
            // Both empty is allowed (no client assigned)
        }

        if (!empty($validated['persona_id']) && !empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de cliente, no ambos'], 422);
        }

        if (!isset($validated['estado'])) {
            $validated['estado'] = 'recibido';
        }

        if (!isset($validated['editor_ids'])) {
            $validated['editor_ids'] = [];
        }

        $trabajo = TrabajoEdicion::create($validated);

        CuentaPorCobrar::create([
            'edicion_video_id' => $trabajo->id,
            'monto_total' => $validated['precio_cobrado'] ?? 0,
            'monto_abonado' => 0,
            'estado' => 'pendiente',
            'es_legacy' => false,
        ]);

        return response()->json([
            'message' => 'Trabajo de edición creado exitosamente.',
            'data' => $this->formatTrabajo($trabajo),
        ], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $trabajo = TrabajoEdicion::with('reservaPodcast', 'cliente', 'clienteExterno')->findOrFail($id);
        return response()->json(['data' => $this->formatTrabajo($trabajo)]);
    }

    public function update(Request $request, $id)
    {
        $trabajo = TrabajoEdicion::findOrFail($id);

        $validated = $request->validate([
            'titulo' => 'sometimes|string|max:300',
            'descripcion' => 'nullable|string',
            'fecha_recibo' => 'sometimes|date',
            'fecha_limite' => 'sometimes|date',
            'fecha_entrega' => 'nullable|date',
            'estado' => 'sometimes|string|in:recibido,en_proceso,revision,entregado',
            'editor_ids' => 'nullable|array',
            'editor_ids.*' => 'uuid|exists:personas,id',
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'reserva_podcast_id' => 'nullable|uuid|exists:reservas_podcast,id',
            'precio_cobrado' => 'nullable|numeric|min:0',
            'cobro_registrado' => 'boolean',
            'notas' => 'nullable|string',
        ]);

        if (!empty($validated['persona_id']) && !empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de cliente, no ambos'], 422);
        }

        if (isset($validated['fecha_limite']) && isset($validated['fecha_recibo'])) {
            // Validate after_or_equal only when both are present
        } elseif (isset($validated['fecha_limite']) && !isset($validated['fecha_recibo'])) {
            $validated['fecha_recibo'] = $trabajo->fecha_recibo?->format('Y-m-d');
        }

        $trabajo->update($validated);

        return response()->json([
            'message' => 'Trabajo de edición actualizado exitosamente.',
            'data' => $this->formatTrabajo($trabajo->fresh()),
        ]);
    }

    public function destroy($id)
    {
        $trabajo = TrabajoEdicion::findOrFail($id);
        $trabajo->delete();

        return response()->json([
            'message' => 'Trabajo de edición eliminado exitosamente.',
        ]);
    }

    public function registrarEntrega(Request $request, $id)
    {
        $trabajo = TrabajoEdicion::findOrFail($id);

        $validated = $request->validate([
            'fecha_entrega' => 'required|date',
            'precio_cobrado' => 'nullable|numeric|min:0',
        ]);

        $trabajo->update([
            'estado' => 'entregado',
            'fecha_entrega' => $validated['fecha_entrega'],
            'precio_cobrado' => $validated['precio_cobrado'] ?? $trabajo->precio_cobrado,
        ]);

        return response()->json([
            'message' => 'Entrega registrada exitosamente.',
            'data' => $this->formatTrabajo($trabajo->fresh()),
        ]);
    }

    public function registrarCobro($id)
    {
        $trabajo = TrabajoEdicion::findOrFail($id);

        if ($trabajo->estado !== 'entregado') {
            return response()->json(['message' => 'Solo se puede registrar cobro de trabajos entregados'], 422);
        }

        if ($trabajo->cobro_registrado) {
            return response()->json(['message' => 'El cobro ya fue registrado'], 422);
        }

        $trabajo->update(['cobro_registrado' => true]);

        return response()->json([
            'message' => 'Cobro registrado exitosamente.',
            'data' => $this->formatTrabajo($trabajo->fresh()),
        ]);
    }

    private function formatTrabajo(TrabajoEdicion $t)
    {
        $editoresIds = $t->editor_ids ?? [];
        $editores = [];
        if (!empty($editoresIds)) {
            $personas = Persona::whereIn('id', $editoresIds)->get(['id', 'nombres', 'apellidos']);
            $editores = $personas->toArray();
        }

        $cliente = null;
        if ($t->relationLoaded('cliente') && $t->cliente) {
            $cliente = [
                'id' => $t->cliente->id,
                'nombres' => $t->cliente->nombres,
                'apellidos' => $t->cliente->apellidos,
            ];
        } elseif ($t->persona_id) {
            $persona = Persona::find($t->persona_id, ['id', 'nombres', 'apellidos']);
            if ($persona) {
                $cliente = ['id' => $persona->id, 'nombres' => $persona->nombres, 'apellidos' => $persona->apellidos];
            }
        }

        $clienteExterno = null;
        if ($t->relationLoaded('clienteExterno') && $t->clienteExterno) {
            $ce = $t->clienteExterno;
            $clienteExterno = [
                'id' => $ce->id,
                'nombres' => $ce->nombres,
                'apellidos' => $ce->apellidos,
                'cedula' => $ce->cedula,
                'correo' => $ce->correo,
                'celular' => $ce->celular,
            ];
        }

        return [
            'id' => $t->id,
            'titulo' => $t->titulo,
            'descripcion' => $t->descripcion,
            'fecha_recibo' => $t->fecha_recibo?->format('Y-m-d'),
            'fecha_limite' => $t->fecha_limite?->format('Y-m-d'),
            'fecha_entrega' => $t->fecha_entrega?->format('Y-m-d'),
            'estado' => $t->estado,
            'editor_ids' => $t->getRawOriginal('editor_ids') ? json_decode($t->getRawOriginal('editor_ids'), true) : [],
            'editores' => $editores,
            'persona_id' => $t->persona_id,
            'cliente_externo_id' => $t->cliente_externo_id,
            'cliente' => $cliente,
            'cliente_externo' => $clienteExterno,
            'reserva_podcast_id' => $t->reserva_podcast_id,
            'precio_cobrado' => $t->precio_cobrado,
            'cobro_registrado' => $t->cobro_registrado,
            'notas' => $t->notas,
            'created_at' => $t->created_at?->toISOString(),
        ];
    }
}

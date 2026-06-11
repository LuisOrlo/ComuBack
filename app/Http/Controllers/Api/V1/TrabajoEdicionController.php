<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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

        $trabajos = $query->with('reservaPodcast')->orderBy('fecha_limite')
            ->paginate($request->get('per_page', 15));

        $trabajos->each(function ($t) {
            $editoresIds = $t->editor_ids ?? [];
            if (!empty($editoresIds)) {
                $personas = Persona::whereIn('id', $editoresIds)->get(['id', 'nombres', 'apellidos']);
                $t->setRelation('editores_list', $personas);
            } else {
                $t->setRelation('editores_list', collect());
            }
            unset($t->editor_ids);
        });

        return response()->json([
            'data' => $trabajos->map(function ($t) {
                return [
                    'id' => $t->id,
                    'titulo' => $t->titulo,
                    'descripcion' => $t->descripcion,
                    'fecha_recibo' => $t->fecha_recibo?->format('Y-m-d'),
                    'fecha_limite' => $t->fecha_limite?->format('Y-m-d'),
                    'fecha_entrega' => $t->fecha_entrega?->format('Y-m-d'),
                    'nivel' => $t->nivel,
                    'estado' => $t->estado,
                    'editor_ids' => $t->getRawOriginal('editor_ids') ? json_decode($t->getRawOriginal('editor_ids'), true) : [],
                    'editores' => $t->editores_list->toArray(),
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
            'nivel' => 'required|string|in:basica,estandar,premium',
            'estado' => 'nullable|string|in:recibido,en_proceso,revision,entregado',
            'editor_ids' => 'nullable|array',
            'editor_ids.*' => 'uuid|exists:personas,id',
            'reserva_podcast_id' => 'nullable|uuid|exists:reservas_podcast,id',
            'precio_cobrado' => 'nullable|numeric|min:0',
            'cobro_registrado' => 'boolean',
            'notas' => 'nullable|string',
        ]);

        if (!isset($validated['estado'])) {
            $validated['estado'] = 'recibido';
        }

        if (!isset($validated['editor_ids'])) {
            $validated['editor_ids'] = [];
        }

        $trabajo = TrabajoEdicion::create($validated);

        return response()->json([
            'message' => 'Trabajo de edición creado exitosamente.',
            'data' => $this->formatTrabajo($trabajo),
        ], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $trabajo = TrabajoEdicion::with('reservaPodcast')->findOrFail($id);
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
            'nivel' => 'sometimes|string|in:basica,estandar,premium',
            'estado' => 'sometimes|string|in:recibido,en_proceso,revision,entregado',
            'editor_ids' => 'nullable|array',
            'editor_ids.*' => 'uuid|exists:personas,id',
            'reserva_podcast_id' => 'nullable|uuid|exists:reservas_podcast,id',
            'precio_cobrado' => 'nullable|numeric|min:0',
            'cobro_registrado' => 'boolean',
            'notas' => 'nullable|string',
        ]);

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
        $editores = !empty($editoresIds)
            ? Persona::whereIn('id', $editoresIds)->get(['id', 'nombres', 'apellidos'])->toArray()
            : [];

        if ($t->relationLoaded('reservaPodcast')) {
            // already loaded
        }

        return [
            'id' => $t->id,
            'titulo' => $t->titulo,
            'descripcion' => $t->descripcion,
            'fecha_recibo' => $t->fecha_recibo?->format('Y-m-d'),
            'fecha_limite' => $t->fecha_limite?->format('Y-m-d'),
            'fecha_entrega' => $t->fecha_entrega?->format('Y-m-d'),
            'nivel' => $t->nivel,
            'estado' => $t->estado,
            'editor_ids' => $t->getRawOriginal('editor_ids') ? json_decode($t->getRawOriginal('editor_ids'), true) : [],
            'editores' => $editores,
            'reserva_podcast_id' => $t->reserva_podcast_id,
            'precio_cobrado' => $t->precio_cobrado,
            'cobro_registrado' => $t->cobro_registrado,
            'notas' => $t->notas,
            'created_at' => $t->created_at?->toISOString(),
        ];
    }
}

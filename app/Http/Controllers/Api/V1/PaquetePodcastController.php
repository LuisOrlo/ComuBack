<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Services\ItemPaquetePodcast;
use App\Models\Services\PaquetePodcast;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaquetePodcastController extends Controller
{
    public function index(Request $request)
    {
        $paquetes = PaquetePodcast::with('items')->orderBy('nombre')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => $paquetes->map(fn ($p) => $this->formatPaquete($p))->values(),
            'meta' => [
                'current_page' => $paquetes->currentPage(),
                'last_page' => $paquetes->lastPage(),
                'per_page' => $paquetes->perPage(),
                'total' => $paquetes->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:200',
            'descripcion' => 'nullable|string',
            'precio_por_hora' => 'required|numeric|min:0',
            'activo' => 'boolean',
            'items' => 'nullable|array',
            'items.*.nombre' => 'required|string|max:200',
        ]);

        $data = [
            'nombre' => $validated['nombre'],
            'descripcion' => $validated['descripcion'] ?? null,
            'precio_base' => $validated['precio_por_hora'],
            'es_activo' => $validated['activo'] ?? true,
        ];

        $paquete = PaquetePodcast::create($data);

        if (!empty($validated['items'])) {
            foreach ($validated['items'] as $item) {
                $paquete->items()->create(['descripcion' => $item['nombre']]);
            }
        }

        return response()->json([
            'message' => 'Paquete creado exitosamente.',
            'data' => $this->formatPaquete($paquete->fresh()->load('items')),
        ], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $paquete = PaquetePodcast::with('items')->findOrFail((int) $id);
        return response()->json(['data' => $this->formatPaquete($paquete)]);
    }

    public function update(Request $request, $id)
    {
        $paquete = PaquetePodcast::findOrFail((int) $id);

        $validated = $request->validate([
            'nombre' => 'sometimes|string|max:200',
            'descripcion' => 'nullable|string',
            'precio_por_hora' => 'sometimes|numeric|min:0',
            'activo' => 'boolean',
            'items' => 'nullable|array',
            'items.*.nombre' => 'required|string|max:200',
        ]);

        $data = [];
        if (isset($validated['nombre'])) $data['nombre'] = $validated['nombre'];
        if (array_key_exists('descripcion', $validated)) $data['descripcion'] = $validated['descripcion'];
        if (isset($validated['precio_por_hora'])) $data['precio_base'] = $validated['precio_por_hora'];
        if (isset($validated['activo'])) $data['es_activo'] = $validated['activo'];

        $paquete->update($data);

        if (isset($validated['items'])) {
            $paquete->items()->delete();
            foreach ($validated['items'] as $item) {
                $paquete->items()->create(['descripcion' => $item['nombre']]);
            }
        }

        return response()->json([
            'message' => 'Paquete actualizado exitosamente.',
            'data' => $this->formatPaquete($paquete->fresh()->load('items')),
        ]);
    }

    public function destroy($id)
    {
        $paquete = PaquetePodcast::findOrFail((int) $id);
        $paquete->items()->delete();
        $paquete->delete();

        return response()->json([
            'message' => 'Paquete eliminado exitosamente.',
        ]);
    }

    private function formatPaquete(PaquetePodcast $p)
    {
        return [
            'id' => (string) $p->id,
            'nombre' => $p->nombre,
            'descripcion' => $p->descripcion,
            'precio_por_hora' => (float) $p->precio_base,
            'activo' => $p->es_activo,
            'items' => $p->relationLoaded('items') && $p->items->isNotEmpty()
                ? $p->items->map(fn ($i) => [
                    'id' => (string) $i->id,
                    'nombre' => $i->descripcion,
                    'incluido' => true,
                ])->values()->toArray()
                : [],
        ];
    }
}

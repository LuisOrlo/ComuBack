<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Services\AsignacionPersonal;
use App\Models\Services\ReservaPodcast;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReservaPodcastController extends Controller
{
    public function index(Request $request)
    {
        $query = ReservaPodcast::with(['paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona']);

        if ($request->has('paquete_id')) {
            $query->where('paquete_id', (int) $request->paquete_id);
        }

        if ($request->has('fecha')) {
            $query->where('fecha_reserva', $request->fecha);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        $reservas = $query->orderBy('fecha_reserva')->orderBy('hora_inicio')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => $reservas->map(fn ($r) => $this->formatReserva($r))->values(),
            'meta' => [
                'current_page' => $reservas->currentPage(),
                'last_page' => $reservas->lastPage(),
                'per_page' => $reservas->perPage(),
                'total' => $reservas->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'paquete_id_raw' => $request->paquete_id,
        ]);

        $validated = $request->validate([
            'paquete_id_raw' => 'required|integer|exists:paquetes_podcast,id',
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'fecha_reserva' => 'required|date',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'precio_total' => 'required|numeric|min:0',
            'notas' => 'nullable|string',
            'estado' => 'nullable|string|in:pendiente,reservado,confirmado,en_progreso,completado,cancelado',
            'asignaciones' => 'nullable|array',
            'asignaciones.*.persona_id' => 'required|uuid|exists:personas,id',
            'asignaciones.*.rol' => 'nullable|string|max:100',
        ]);

        $data = [
            'paquete_id' => (int) $validated['paquete_id_raw'],
            'persona_id' => $validated['persona_id'] ?? null,
            'cliente_externo_id' => $validated['cliente_externo_id'] ?? null,
            'fecha_reserva' => $validated['fecha_reserva'],
            'hora_inicio' => $validated['hora_inicio'],
            'hora_fin' => $validated['hora_fin'],
            'precio_total' => $validated['precio_total'],
            'observaciones' => $validated['notas'] ?? null,
            'estado' => $validated['estado'] ?? 'reservado',
        ];

        if ($data['estado'] === 'pendiente') {
            $data['estado'] = 'reservado';
        }

        if (empty($data['persona_id']) && empty($data['cliente_externo_id'])) {
            return response()->json(['message' => 'Debe especificar un responsable (persona o cliente externo)'], 422);
        }

        if (!empty($data['persona_id']) && !empty($data['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable, no ambos'], 422);
        }

        $conflicto = ReservaPodcast::where('paquete_id', $data['paquete_id'])
            ->where('fecha_reserva', $data['fecha_reserva'])
            ->where('estado', '!=', 'cancelado')
            ->where(function ($q) use ($data) {
                $q->where(function ($q2) use ($data) {
                    $q2->where('hora_inicio', '<', $data['hora_fin'])
                       ->where('hora_fin', '>', $data['hora_inicio']);
                });
            })->exists();

        if ($conflicto) {
            return response()->json(['message' => 'El estudio ya está reservado en el horario seleccionado'], 422);
        }

        $reserva = ReservaPodcast::create($data);

        if (!empty($validated['asignaciones'])) {
            foreach ($validated['asignaciones'] as $asignacion) {
                $reserva->asignacionesPersonal()->create([
                    'persona_id' => $asignacion['persona_id'],
                    'rol_en_servicio' => $asignacion['rol'] ?? null,
                ]);
            }
        }

        return response()->json([
            'message' => 'Reserva creada exitosamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona',
            ])),
        ], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $reserva = ReservaPodcast::with([
            'paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona',
        ])->findOrFail($id);
        return response()->json(['data' => $this->formatReserva($reserva)]);
    }

    public function update(Request $request, $id)
    {
        $reserva = ReservaPodcast::findOrFail($id);

        $request->merge([
            'paquete_id_raw' => $request->paquete_id,
        ]);

        $validated = $request->validate([
            'paquete_id_raw' => 'sometimes|integer|exists:paquetes_podcast,id',
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'fecha_reserva' => 'sometimes|date',
            'hora_inicio' => 'sometimes|date_format:H:i',
            'hora_fin' => 'sometimes|date_format:H:i|after:hora_inicio',
            'precio_total' => 'sometimes|numeric|min:0',
            'notas' => 'nullable|string',
            'estado' => 'sometimes|string|in:pendiente,reservado,confirmado,en_progreso,completado,cancelado',
            'asignaciones' => 'nullable|array',
            'asignaciones.*.persona_id' => 'required|uuid|exists:personas,id',
            'asignaciones.*.rol' => 'nullable|string|max:100',
        ]);

        $data = [];
        if (isset($validated['paquete_id_raw'])) $data['paquete_id'] = (int) $validated['paquete_id_raw'];
        if (array_key_exists('persona_id', $validated)) $data['persona_id'] = $validated['persona_id'];
        if (array_key_exists('cliente_externo_id', $validated)) $data['cliente_externo_id'] = $validated['cliente_externo_id'];
        if (isset($validated['fecha_reserva'])) $data['fecha_reserva'] = $validated['fecha_reserva'];
        if (isset($validated['hora_inicio'])) $data['hora_inicio'] = $validated['hora_inicio'];
        if (isset($validated['hora_fin'])) $data['hora_fin'] = $validated['hora_fin'];
        if (isset($validated['precio_total'])) $data['precio_total'] = $validated['precio_total'];
        if (array_key_exists('notas', $validated)) $data['observaciones'] = $validated['notas'];
        if (isset($validated['estado'])) $data['estado'] = $validated['estado'] === 'pendiente' ? 'reservado' : $validated['estado'];

        if (empty($data['persona_id']) && empty($data['cliente_externo_id'])) {
            return response()->json(['message' => 'Debe especificar un responsable (persona o cliente externo)'], 422);
        }

        if (!empty($data['persona_id']) && !empty($data['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable, no ambos'], 422);
        }

        $reserva->update($data);

        if (array_key_exists('asignaciones', $validated)) {
            $reserva->asignacionesPersonal()->delete();
            if (!empty($validated['asignaciones'])) {
                foreach ($validated['asignaciones'] as $asignacion) {
                    $reserva->asignacionesPersonal()->create([
                        'persona_id' => $asignacion['persona_id'],
                        'rol_en_servicio' => $asignacion['rol'] ?? null,
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Reserva actualizada exitosamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona',
            ])),
        ]);
    }

    public function destroy($id)
    {
        $reserva = ReservaPodcast::findOrFail($id);
        $reserva->asignacionesPersonal()->delete();
        $reserva->delete();

        return response()->json([
            'message' => 'Reserva eliminada exitosamente.',
        ]);
    }

    public function registrarPago($id)
    {
        $reserva = ReservaPodcast::findOrFail($id);

        if ($reserva->estado === 'cancelado') {
            return response()->json(['message' => 'No se puede registrar pago de una reserva cancelada'], 422);
        }

        $reserva->update(['estado' => 'confirmado']);

        return response()->json([
            'message' => 'Pago registrado exitosamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona',
            ])),
        ]);
    }

    private function formatReserva(ReservaPodcast $r)
    {
        $paquete = null;
        if ($r->relationLoaded('paquete') && $r->paquete) {
            $p = $r->paquete;
            $paquete = [
                'id' => (string) $p->id,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'precio_por_hora' => (float) $p->precio_base,
                'activo' => $p->es_activo,
                'items' => $p->relationLoaded('items') && $p->items
                    ? $p->items->map(fn ($i) => [
                        'id' => (string) $i->id,
                        'nombre' => $i->descripcion,
                        'incluido' => true,
                    ])->toArray()
                    : [],
            ];
        }

        $asignaciones = $r->relationLoaded('asignacionesPersonal') && $r->asignacionesPersonal->isNotEmpty()
            ? $r->asignacionesPersonal->map(fn ($a) => [
                'id' => $a->id,
                'persona_id' => $a->persona_id,
                'rol' => $a->rol_en_servicio,
                'persona' => $a->relationLoaded('persona') && $a->persona
                    ? ['id' => $a->persona->id, 'nombres' => $a->persona->nombres, 'apellidos' => $a->persona->apellidos]
                    : null,
            ])->values()->toArray()
            : [];

        return [
            'id' => $r->id,
            'paquete_id' => (string) $r->paquete_id,
            'persona_id' => $r->persona_id,
            'cliente_externo_id' => $r->cliente_externo_id,
            'fecha_reserva' => $r->fecha_reserva?->format('Y-m-d'),
            'hora_inicio' => $r->hora_inicio,
            'hora_fin' => $r->hora_fin,
            'precio_total' => (float) $r->precio_total,
            'pago_registrado' => in_array($r->estado, ['confirmado', 'en_progreso', 'completado']),
            'estado' => $r->estado === 'reservado' ? 'pendiente' : $r->estado,
            'notas' => $r->observaciones,
            'asignaciones' => $asignaciones,
            'paquete' => $paquete,
            'persona' => $r->relationLoaded('persona') && $r->persona
                ? ['id' => $r->persona->id, 'nombres' => $r->persona->nombres, 'apellidos' => $r->persona->apellidos]
                : null,
            'cliente_externo' => $r->relationLoaded('clienteExterno') && $r->clienteExterno
                ? [
                    'id' => $r->clienteExterno->id,
                    'nombres' => $r->clienteExterno->nombres,
                    'cedula' => $r->clienteExterno->cedula,
                    'correo' => $r->clienteExterno->correo,
                    'celular' => $r->clienteExterno->celular,
                ]
                : null,
            'created_at' => $r->created_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use App\Models\Services\AsignacionPersonal;
use App\Models\Services\ReservaPodcast;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReservaPodcastController extends Controller
{
    public function index(Request $request)
    {
        $query = ReservaPodcast::with(['paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona', 'cuentaPorCobrar']);

        if ($request->has('paquete_id')) {
            $query->where('paquete_id', (int) $request->paquete_id);
        }

        if ($request->has('fecha')) {
            $query->where('fecha_reserva', $request->fecha);
        }

        if ($request->has('fecha_desde')) {
            $query->where('fecha_reserva', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->where('fecha_reserva', '<=', $request->fecha_hasta);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        $perPage = $request->get('per_page', 15);
        if ($perPage === 'all' || $request->boolean('all')) {
            $reservas = $query->orderBy('fecha_reserva')->orderBy('hora_inicio')->get();
            return response()->json([
                'data' => $reservas->map(fn ($r) => $this->formatReserva($r))->values(),
                'meta' => [
                    'total' => $reservas->count(),
                    'all' => true,
                ],
            ]);
        }

        $reservas = $query->orderBy('fecha_reserva')->orderBy('hora_inicio')
            ->paginate((int) $perPage);

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
            'precio_original' => 'nullable|numeric|min:0',
            'monto_descuento' => 'nullable|numeric|min:0',
            'motivo_descuento' => 'nullable|string|max:255',
            'notas' => 'nullable|string',
            'titulo' => 'nullable|string|max:255',
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
            'precio_original' => $validated['precio_original'] ?? null,
            'monto_descuento' => $validated['monto_descuento'] ?? 0,
            'motivo_descuento' => $validated['motivo_descuento'] ?? null,
            'observaciones' => $validated['notas'] ?? null,
            'titulo' => $validated['titulo'] ?? null,
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

        return DB::transaction(function () use ($data, $validated) {
            $conflicto = ReservaPodcast::where('fecha_reserva', $data['fecha_reserva'])
                ->where('estado', '!=', 'cancelado')
                ->where('hora_inicio', '<', $data['hora_fin'])
                ->where('hora_fin', '>', $data['hora_inicio'])
                ->lockForUpdate()
                ->exists();

            if ($conflicto) {
                return response()->json(['message' => 'El estudio ya está reservado en el horario seleccionado'], 422);
            }

            $reserva = ReservaPodcast::create($data);

            CuentaPorCobrar::create([
                'reserva_podcast_id' => $reserva->id,
                'monto_total' => $data['precio_total'],
                'monto_abonado' => 0,
                'estado' => 'pendiente',
                'es_legacy' => false,
            ]);

            if (!empty($validated['asignaciones'])) {
                foreach ($validated['asignaciones'] as $asignacion) {
                    $reserva->asignacionesPersonal()->create([
                        'persona_id' => $asignacion['persona_id'],
                        'rol_en_servicio' => $asignacion['rol'] ?? null,
                    ]);
                }
            }

            Cache::forget('finance.resumen');

            return response()->json([
                'message' => 'Reserva creada exitosamente.',
                'data' => $this->formatReserva($reserva->fresh()->load([
                    'paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona', 'cuentaPorCobrar',
                ])),
            ], Response::HTTP_CREATED);
        });
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
            'precio_original' => 'nullable|numeric|min:0',
            'monto_descuento' => 'nullable|numeric|min:0',
            'motivo_descuento' => 'nullable|string|max:255',
            'notas' => 'nullable|string',
            'titulo' => 'nullable|string|max:255',
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
        if (array_key_exists('precio_original', $validated)) $data['precio_original'] = $validated['precio_original'];
        if (array_key_exists('monto_descuento', $validated)) $data['monto_descuento'] = $validated['monto_descuento'];
        if (array_key_exists('motivo_descuento', $validated)) $data['motivo_descuento'] = $validated['motivo_descuento'];
        if (array_key_exists('notas', $validated)) $data['observaciones'] = $validated['notas'];
        if (array_key_exists('titulo', $validated)) $data['titulo'] = $validated['titulo'];
        if (isset($validated['estado'])) $data['estado'] = $validated['estado'] === 'pendiente' ? 'reservado' : $validated['estado'];

        if (empty($data['persona_id']) && empty($data['cliente_externo_id']) && empty($reserva->persona_id) && empty($reserva->cliente_externo_id)) {
            return response()->json(['message' => 'Debe especificar un responsable (persona o cliente externo)'], 422);
        }

        if (!empty($data['persona_id']) && !empty($data['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable, no ambos'], 422);
        }

        return DB::transaction(function () use ($reserva, $data, $validated) {
            $fecha = $data['fecha_reserva'] ?? $reserva->fecha_reserva?->format('Y-m-d');
            $inicio = $data['hora_inicio'] ?? $reserva->hora_inicio;
            $fin = $data['hora_fin'] ?? $reserva->hora_fin;
            $estado = $data['estado'] ?? $reserva->estado;

            if ($estado !== 'cancelado') {
                $conflicto = ReservaPodcast::where('id', '!=', $reserva->id)
                    ->where('fecha_reserva', $fecha)
                    ->where('estado', '!=', 'cancelado')
                    ->where('hora_inicio', '<', $fin)
                    ->where('hora_fin', '>', $inicio)
                    ->lockForUpdate()
                    ->exists();

                if ($conflicto) {
                    return response()->json(['message' => 'El estudio ya está reservado en el horario seleccionado'], 422);
                }
            }

            $reserva->update($data);

            if (isset($data['precio_total'])) {
                $cuenta = CuentaPorCobrar::where('reserva_podcast_id', $reserva->id)->first();
                if ($cuenta) {
                    $cuenta->update(['monto_total' => $data['precio_total']]);
                }
            }

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

            Cache::forget('finance.resumen');

            return response()->json([
                'message' => 'Reserva actualizada exitosamente.',
                'data' => $this->formatReserva($reserva->fresh()->load([
                    'paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona', 'cuentaPorCobrar',
                ])),
            ]);
        });
    }

    public function destroy($id)
    {
        $reserva = ReservaPodcast::findOrFail($id);
        $reserva->asignacionesPersonal()->delete();
        CuentaPorCobrar::where('reserva_podcast_id', $id)->delete();
        $reserva->delete();

        Cache::forget('finance.resumen');

        return response()->json([
            'message' => 'Reserva eliminada exitosamente.',
        ]);
    }

    public function registrarPago(Request $request, $id)
    {
        $reserva = ReservaPodcast::findOrFail($id);

        if ($reserva->estado === 'cancelado') {
            return response()->json(['message' => 'No se puede registrar pago de una reserva cancelada'], 422);
        }

        $validated = $request->validate([
            'monto' => 'nullable|numeric|min:0.01',
            'metodo_pago' => 'nullable|string',
            'comprobante_url' => 'nullable|string',
            'fecha_pago' => 'nullable|date',
            'observaciones' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($reserva, $validated) {
            $cuenta = CuentaPorCobrar::firstOrCreate(
                ['reserva_podcast_id' => $reserva->id],
                [
                    'monto_total' => $reserva->precio_total ?? 0,
                    'monto_abonado' => 0,
                    'estado' => 'pendiente',
                    'es_legacy' => false,
                ]
            );

            $saldo = max(0, (float) $cuenta->monto_total - (float) $cuenta->monto_abonado);
            $monto = isset($validated['monto']) ? (float) $validated['monto'] : $saldo;

            if ($monto <= 0 && $saldo <= 0) {
                return response()->json(['message' => 'La reserva ya se encuentra totalmente pagada'], 422);
            }

            if ($monto > ($saldo + 0.01)) {
                return response()->json(['message' => "El monto (\${$monto}) supera el saldo pendiente (\${$saldo})"], 422);
            }

            $personaId = auth()->user()->persona_id ?? null;
            if ($personaId && !\App\Models\Persona::where('id', $personaId)->exists()) {
                $personaId = null;
            }

            if ($monto > 0) {
                TransaccionIngreso::create([
                    'cuenta_cobrar_id' => $cuenta->id,
                    'monto' => $monto,
                    'metodo_pago' => $validated['metodo_pago'] ?? 'efectivo',
                    'comprobante_url' => $validated['comprobante_url'] ?? null,
                    'fecha_pago' => $validated['fecha_pago'] ?? now()->toDateString(),
                    'registrado_por' => $personaId,
                    'observaciones' => $validated['observaciones'] ?? 'Pago registrado en reserva de podcast',
                    'estado_verificacion' => 'aprobado',
                    'verificado_por' => $personaId,
                    'fecha_verificacion' => now(),
                ]);

                $cuenta->monto_abonado += $monto;
                $nuevoSaldo = max(0, (float) $cuenta->monto_total - (float) $cuenta->monto_abonado);
                $cuenta->estado = $nuevoSaldo <= 0.01 ? 'pagado' : 'abonado';
                $cuenta->save();
            }

            // Separar estado operativo del financiero:
            // Si la reserva estaba en 'reservado', se confirma la reserva. No forzar 'completado'.
            if ($reserva->estado === 'reservado') {
                $reserva->update(['estado' => 'confirmado']);
            }

            Cache::forget('finance.resumen');

            return response()->json([
                'message' => 'Pago registrado exitosamente.',
                'data' => $this->formatReserva($reserva->fresh()->load([
                    'paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona', 'cuentaPorCobrar',
                ])),
            ]);
        });
    }

    public function cambiarEstado(Request $request, $id)
    {
        $validated = $request->validate([
            'estado' => 'required|in:pendiente,reservado,confirmado,en_progreso,completado,cancelado',
        ]);

        $reserva = ReservaPodcast::findOrFail($id);
        $estado = $validated['estado'] === 'pendiente' ? 'reservado' : $validated['estado'];

        if ($reserva->estado === 'cancelado' && $estado !== 'cancelado') {
            $fecha = $reserva->fecha_reserva?->format('Y-m-d');
            $conflicto = ReservaPodcast::where('id', '!=', $reserva->id)
                ->where('fecha_reserva', $fecha)
                ->where('estado', '!=', 'cancelado')
                ->where('hora_inicio', '<', $reserva->hora_fin)
                ->where('hora_fin', '>', $reserva->hora_inicio)
                ->exists();

            if ($conflicto) {
                return response()->json(['message' => 'No se puede reactivar la reserva porque el estudio ya está ocupado en ese horario'], 422);
            }
        }

        $reserva->update(['estado' => $estado]);

        Cache::forget('finance.resumen');

        return response()->json([
            'message' => 'Estado actualizado correctamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'paquete.items', 'persona', 'clienteExterno', 'asignacionesPersonal.persona', 'cuentaPorCobrar',
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

        $cuenta = $r->relationLoaded('cuentaPorCobrar') ? $r->cuentaPorCobrar : null;
        $pagoRegistrado = $cuenta
            ? ($cuenta->monto_total > 0 && $cuenta->monto_abonado >= $cuenta->monto_total)
            : in_array($r->estado, ['confirmado', 'en_progreso', 'completado']);

        return [
            'id' => $r->id,
            'paquete_id' => (string) $r->paquete_id,
            'persona_id' => $r->persona_id,
            'cliente_externo_id' => $r->cliente_externo_id,
            'fecha_reserva' => $r->fecha_reserva?->format('Y-m-d'),
            'hora_inicio' => $r->hora_inicio,
            'hora_fin' => $r->hora_fin,
            'precio_total' => (float) $r->precio_total,
            'pago_registrado' => $pagoRegistrado,
            'pago_abonado' => $cuenta ? ($cuenta->monto_abonado > 0) : false,
            'estado' => $r->estado === 'reservado' ? 'pendiente' : $r->estado,
            'titulo' => $r->titulo,
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
            'cuenta_por_cobrar' => $r->relationLoaded('cuentaPorCobrar') && $r->cuentaPorCobrar
                ? [
                    'id' => $r->cuentaPorCobrar->id,
                    'monto_total' => (float) $r->cuentaPorCobrar->monto_total,
                    'monto_abonado' => (float) $r->cuentaPorCobrar->monto_abonado,
                    'saldo_pendiente' => (float) $r->cuentaPorCobrar->obtenerSaldoPendiente(),
                    'estado' => $r->cuentaPorCobrar->estado,
                ]
                : null,
        ];
    }
}

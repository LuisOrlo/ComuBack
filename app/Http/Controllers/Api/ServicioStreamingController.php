<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\Services\ServicioStreaming;
use App\Models\TransaccionIngreso;
use App\Services\StreamingConflictValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServicioStreamingController extends Controller
{
    public function __construct(
        private readonly StreamingConflictValidator $conflictValidator
    ) {}

    /**
     * Listado paginado de coberturas de streaming con Eager Loading estricto (Anti N+1).
     */
    public function index(Request $request)
    {
        $query = ServicioStreaming::with([
            'persona',
            'clienteExterno',
            'ciudad',
            'asignacionesPersonal.persona',
            'cuentaPorCobrar.transacciones',
        ]);

        if ($request->has('fecha')) {
            $query->where('fecha_evento', $request->fecha);
        }

        if ($request->has('fecha_desde')) {
            $query->where('fecha_evento', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->where('fecha_evento', '<=', $request->fecha_hasta);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('ciudad_id')) {
            $query->where('ciudad_id', (int) $request->ciudad_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('titulo', 'ilike', "%{$search}%")
                  ->orWhere('lugar', 'ilike', "%{$search}%")
                  ->orWhere('direccion', 'ilike', "%{$search}%")
                  ->orWhere('descripcion', 'ilike', "%{$search}%")
                  ->orWhereHas('persona', function ($pq) use ($search) {
                      $pq->where('nombres', 'ilike', "%{$search}%")
                         ->orWhere('apellidos', 'ilike', "%{$search}%")
                         ->orWhere('cedula', 'ilike', "%{$search}%");
                  })
                  ->orWhereHas('clienteExterno', function ($cq) use ($search) {
                      $cq->where('nombres', 'ilike', "%{$search}%")
                         ->orWhere('apellidos', 'ilike', "%{$search}%")
                         ->orWhere('razon_social', 'ilike', "%{$search}%")
                         ->orWhere('ruc', 'ilike', "%{$search}%");
                  });
            });
        }

        $perPage = $request->get('per_page', 15);
        if ($perPage === 'all' || $request->boolean('all')) {
            $streamings = $query->orderBy('fecha_evento', 'desc')->orderBy('hora_inicio')->get();
            return response()->json([
                'data' => $streamings->map(fn ($s) => $this->formatStreaming($s))->values(),
                'meta' => [
                    'total' => $streamings->count(),
                    'all' => true,
                ],
            ]);
        }

        $streamings = $query->orderBy('fecha_evento', 'desc')->orderBy('hora_inicio')
            ->paginate((int) $perPage);

        return response()->json([
            'data' => $streamings->map(fn ($s) => $this->formatStreaming($s))->values(),
            'meta' => [
                'current_page' => $streamings->currentPage(),
                'last_page' => $streamings->lastPage(),
                'per_page' => $streamings->perPage(),
                'total' => $streamings->total(),
            ],
        ]);
    }

    /**
     * Registro de nuevo servicio de streaming con validaciones y transacción atómica.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'titulo' => 'nullable|string|max:255',
            'fecha_evento' => 'required|date',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'lugar' => 'required|string|max:300',
            'direccion' => 'nullable|string|max:500',
            'referencias_ubicacion' => 'nullable|string',
            'equipos_detalle' => 'nullable|string',
            'descripcion' => 'nullable|string',
            'observaciones' => 'nullable|string',
            'precio_original' => 'nullable|numeric|min:0',
            'monto_descuento' => 'nullable|numeric|min:0',
            'motivo_descuento' => 'nullable|string|max:255',
            'monto_recargo' => 'nullable|numeric|min:0',
            'motivo_recargo' => 'nullable|string|max:255',
            'precio_total' => 'required|numeric|min:0',
            'estado' => 'nullable|string|in:reservado,confirmado,en_progreso,completado,cancelado',
            'asignaciones' => 'nullable|array',
            'asignaciones.*.persona_id' => 'required|uuid|exists:personas,id',
            'asignaciones.*.rol' => 'nullable|string|max:100',
        ]);

        $this->validateExclusividadCliente($validated);

        // Validación de disponibilidad de personal técnico asignado
        if (!empty($validated['asignaciones'])) {
            $personaIds = array_column($validated['asignaciones'], 'persona_id');
            $disponibilidad = $this->conflictValidator->validarDisponibilidadStaff(
                $personaIds,
                $validated['fecha_evento'],
                $validated['hora_inicio'],
                $validated['hora_fin']
            );

            if (!$disponibilidad['valido']) {
                return response()->json([
                    'message' => 'Uno o más miembros del personal técnico presentan conflictos de horario.',
                    'conflictos' => $disponibilidad['conflictos'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $data = [
            'persona_id' => $validated['persona_id'] ?? null,
            'cliente_externo_id' => $validated['cliente_externo_id'] ?? null,
            'ciudad_id' => $validated['ciudad_id'] ?? null,
            'titulo' => $validated['titulo'] ?? null,
            'fecha_evento' => $validated['fecha_evento'],
            'hora_inicio' => $validated['hora_inicio'],
            'hora_fin' => $validated['hora_fin'],
            'lugar' => $validated['lugar'],
            'direccion' => $validated['direccion'] ?? null,
            'referencias_ubicacion' => $validated['referencias_ubicacion'] ?? null,
            'equipos_detalle' => $validated['equipos_detalle'] ?? null,
            'descripcion' => $validated['descripcion'] ?? null,
            'observaciones' => $validated['observaciones'] ?? null,
            'precio_original' => $validated['precio_original'] ?? $validated['precio_total'],
            'monto_descuento' => $validated['monto_descuento'] ?? 0,
            'motivo_descuento' => $validated['motivo_descuento'] ?? null,
            'monto_recargo' => $validated['monto_recargo'] ?? 0,
            'motivo_recargo' => $validated['motivo_recargo'] ?? null,
            'precio_total' => $validated['precio_total'],
            'estado' => $validated['estado'] ?? 'reservado',
        ];

        return DB::transaction(function () use ($data, $validated) {
            $streaming = ServicioStreaming::create($data);

            CuentaPorCobrar::create([
                'servicio_streaming_id' => $streaming->id,
                'monto_total' => $data['precio_total'],
                'monto_abonado' => 0,
                'estado' => 'pendiente',
                'es_legacy' => false,
            ]);

            if (!empty($validated['asignaciones'])) {
                foreach ($validated['asignaciones'] as $asig) {
                    $streaming->asignacionesPersonal()->create([
                        'persona_id' => $asig['persona_id'],
                        'rol_en_servicio' => $asig['rol'] ?? 'Técnico de Streaming',
                    ]);
                }
            }

            Cache::forget('finance.resumen');

            return response()->json([
                'message' => 'Servicio de streaming registrado exitosamente.',
                'data' => $this->formatStreaming($streaming->fresh()->load([
                    'persona', 'clienteExterno', 'ciudad',
                    'asignacionesPersonal.persona', 'cuentaPorCobrar.transacciones',
                ])),
            ], Response::HTTP_CREATED);
        });
    }

    /**
     * Ficha técnica y detalle del servicio.
     */
    public function show($id)
    {
        $streaming = ServicioStreaming::with([
            'persona',
            'clienteExterno',
            'ciudad',
            'asignacionesPersonal.persona',
            'cuentaPorCobrar.transacciones',
        ])->findOrFail($id);

        return response()->json(['data' => $this->formatStreaming($streaming)]);
    }

    /**
     * Actualización integral del servicio de streaming.
     */
    public function update(Request $request, $id)
    {
        $streaming = ServicioStreaming::findOrFail($id);

        $validated = $request->validate([
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'titulo' => 'nullable|string|max:255',
            'fecha_evento' => 'sometimes|date',
            'hora_inicio' => 'sometimes|date_format:H:i',
            'hora_fin' => 'sometimes|date_format:H:i|after:hora_inicio',
            'lugar' => 'sometimes|string|max:300',
            'direccion' => 'nullable|string|max:500',
            'referencias_ubicacion' => 'nullable|string',
            'equipos_detalle' => 'nullable|string',
            'descripcion' => 'nullable|string',
            'observaciones' => 'nullable|string',
            'precio_original' => 'nullable|numeric|min:0',
            'monto_descuento' => 'nullable|numeric|min:0',
            'motivo_descuento' => 'nullable|string|max:255',
            'monto_recargo' => 'nullable|numeric|min:0',
            'motivo_recargo' => 'nullable|string|max:255',
            'precio_total' => 'sometimes|numeric|min:0',
            'estado' => 'sometimes|string|in:reservado,confirmado,en_progreso,completado,cancelado',
            'asignaciones' => 'nullable|array',
            'asignaciones.*.persona_id' => 'required|uuid|exists:personas,id',
            'asignaciones.*.rol' => 'nullable|string|max:100',
        ]);

        if (array_key_exists('persona_id', $validated) || array_key_exists('cliente_externo_id', $validated)) {
            $this->validateExclusividadCliente([
                'persona_id' => $validated['persona_id'] ?? $streaming->persona_id,
                'cliente_externo_id' => $validated['cliente_externo_id'] ?? $streaming->cliente_externo_id,
            ]);
        }

        $fecha = $validated['fecha_evento'] ?? $streaming->fecha_evento?->format('Y-m-d');
        $inicio = $validated['hora_inicio'] ?? $streaming->hora_inicio;
        $fin = $validated['hora_fin'] ?? $streaming->hora_fin;

        // Validar disponibilidad si hay asignaciones o cambio de horario
        if (array_key_exists('asignaciones', $validated)) {
            $personaIds = array_column($validated['asignaciones'] ?? [], 'persona_id');
        } else {
            $personaIds = $streaming->asignacionesPersonal()->pluck('persona_id')->toArray();
        }

        if (!empty($personaIds) && ($validated['estado'] ?? $streaming->estado) !== 'cancelado') {
            $disponibilidad = $this->conflictValidator->validarDisponibilidadStaff(
                $personaIds,
                $fecha,
                $inicio,
                $fin,
                $streaming->id
            );

            if (!$disponibilidad['valido']) {
                return response()->json([
                    'message' => 'Uno o más miembros del personal técnico presentan conflictos de horario.',
                    'conflictos' => $disponibilidad['conflictos'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return DB::transaction(function () use ($streaming, $validated) {
            $streaming->update($validated);

            // Sincronizar monto total en cuenta por cobrar
            if (isset($validated['precio_total'])) {
                $cuenta = CuentaPorCobrar::where('servicio_streaming_id', $streaming->id)->first();
                if ($cuenta) {
                    $cuenta->update(['monto_total' => $validated['precio_total']]);
                }
            }

            // Sincronizar asignaciones si fueron proporcionadas
            if (array_key_exists('asignaciones', $validated)) {
                $streaming->asignacionesPersonal()->delete();
                foreach ($validated['asignaciones'] ?? [] as $asig) {
                    $streaming->asignacionesPersonal()->create([
                        'persona_id' => $asig['persona_id'],
                        'rol_en_servicio' => $asig['rol'] ?? 'Técnico de Streaming',
                    ]);
                }
            }

            Cache::forget('finance.resumen');

            return response()->json([
                'message' => 'Servicio de streaming actualizado exitosamente.',
                'data' => $this->formatStreaming($streaming->fresh()->load([
                    'persona', 'clienteExterno', 'ciudad',
                    'asignacionesPersonal.persona', 'cuentaPorCobrar.transacciones',
                ])),
            ]);
        });
    }

    /**
     * Eliminación de cobertura (protegida contra cuentas con pagos).
     */
    public function destroy($id)
    {
        $bloqueadaPorPago = false;

        DB::transaction(function () use ($id, &$bloqueadaPorPago) {
            $streaming = ServicioStreaming::whereKey($id)->lockForUpdate()->firstOrFail();
            $cuenta = CuentaPorCobrar::where('servicio_streaming_id', $streaming->id)
                ->lockForUpdate()
                ->first();

            if ($cuenta && $cuenta->transacciones()->exists()) {
                $bloqueadaPorPago = true;
                return;
            }

            $streaming->asignacionesPersonal()->delete();
            $cuenta?->delete();
            $streaming->delete();
        });

        if ($bloqueadaPorPago) {
            return response()->json([
                'message' => 'No se puede eliminar esta cobertura porque registra pagos en su cuenta por cobrar.',
            ], Response::HTTP_CONFLICT);
        }

        Log::channel('audit')->info('servicio_streaming.eliminado', [
            'streaming_id' => $id,
            'usuario_id' => auth()->id(),
            'ip' => request()->ip(),
        ]);

        Cache::forget('finance.resumen');

        return response()->json([
            'message' => 'Servicio de streaming eliminado exitosamente.',
        ]);
    }

    /**
     * Registro de pago o abono financiero.
     */
    public function registrarPago(Request $request, $id)
    {
        $streaming = ServicioStreaming::findOrFail($id);

        if ($streaming->estado === 'cancelado') {
            return response()->json(['message' => 'No se puede registrar pago de una cobertura cancelada'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'monto' => 'nullable|numeric|min:0.01',
            'metodo_pago' => 'nullable|string',
            'comprobante_url' => 'nullable|string',
            'fecha_pago' => 'nullable|date',
            'observaciones' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($streaming, $validated) {
            $cuenta = CuentaPorCobrar::firstOrCreate(
                ['servicio_streaming_id' => $streaming->id],
                [
                    'monto_total' => $streaming->precio_total ?? 0,
                    'monto_abonado' => 0,
                    'estado' => 'pendiente',
                    'es_legacy' => false,
                ]
            );

            $saldo = max(0, (float) $cuenta->monto_total - (float) $cuenta->monto_abonado);
            $monto = isset($validated['monto']) ? (float) $validated['monto'] : $saldo;

            if ($monto <= 0 && $saldo <= 0) {
                return response()->json(['message' => 'El servicio ya se encuentra totalmente pagado'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($monto > ($saldo + 0.01)) {
                return response()->json(['message' => "El monto (\${$monto}) supera el saldo pendiente (\${$saldo})"], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $personaId = auth()->user()->persona_id ?? null;

            if ($monto > 0) {
                TransaccionIngreso::create([
                    'cuenta_cobrar_id' => $cuenta->id,
                    'monto' => $monto,
                    'metodo_pago' => $validated['metodo_pago'] ?? 'efectivo',
                    'comprobante_url' => $validated['comprobante_url'] ?? null,
                    'fecha_pago' => $validated['fecha_pago'] ?? now()->toDateString(),
                    'registrado_por' => $personaId,
                    'observaciones' => $validated['observaciones'] ?? 'Abono registrado en servicio de streaming',
                    'estado_verificacion' => 'aprobado',
                    'verificado_por' => $personaId,
                    'fecha_verificacion' => now(),
                ]);

                $cuenta->monto_abonado += $monto;
                $nuevoSaldo = max(0, (float) $cuenta->monto_total - (float) $cuenta->monto_abonado);
                $cuenta->estado = $nuevoSaldo <= 0.01 ? 'pagado' : 'abonado';
                $cuenta->save();
            }

            // Si estaba en reservado, se confirma automáticamente
            if ($streaming->estado === 'reservado') {
                $streaming->update(['estado' => 'confirmado']);
            }

            Cache::forget('finance.resumen');

            return response()->json([
                'message' => 'Pago registrado exitosamente.',
                'data' => $this->formatStreaming($streaming->fresh()->load([
                    'persona', 'clienteExterno', 'ciudad',
                    'asignacionesPersonal.persona', 'cuentaPorCobrar.transacciones',
                ])),
            ]);
        });
    }

    /**
     * Cambio de estado de la reserva.
     */
    public function cambiarEstado(Request $request, $id)
    {
        $validated = $request->validate([
            'estado' => 'required|in:reservado,confirmado,en_progreso,completado,cancelado',
        ]);

        $streaming = ServicioStreaming::findOrFail($id);
        $nuevoEstado = $validated['estado'];

        // Si se reactiva desde cancelado, verificar disponibilidad del personal
        if ($streaming->estado === 'cancelado' && $nuevoEstado !== 'cancelado') {
            $personaIds = $streaming->asignacionesPersonal()->pluck('persona_id')->toArray();
            if (!empty($personaIds)) {
                $disponibilidad = $this->conflictValidator->validarDisponibilidadStaff(
                    $personaIds,
                    $streaming->fecha_evento?->format('Y-m-d'),
                    $streaming->hora_inicio,
                    $streaming->hora_fin,
                    $streaming->id
                );

                if (!$disponibilidad['valido']) {
                    return response()->json([
                        'message' => 'No se puede reactivar el servicio porque el personal presenta colisión de horario.',
                        'conflictos' => $disponibilidad['conflictos'],
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }
        }

        $streaming->update(['estado' => $nuevoEstado]);
        Cache::forget('finance.resumen');

        return response()->json([
            'message' => 'Estado actualizado correctamente.',
            'data' => $this->formatStreaming($streaming->fresh()->load([
                'persona', 'clienteExterno', 'ciudad',
                'asignacionesPersonal.persona', 'cuentaPorCobrar.transacciones',
            ])),
        ]);
    }

    /**
     * Pre-validación rápida de disponibilidad de staff (con debounce desde el front).
     */
    public function verificarDisponibilidadStaff(Request $request)
    {
        $validated = $request->validate([
            'fecha_evento' => 'required|date',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'persona_ids' => 'required|array',
            'persona_ids.*' => 'required|uuid|exists:personas,id',
            'exclude_id' => 'nullable|uuid',
        ]);

        $resultado = $this->conflictValidator->validarDisponibilidadStaff(
            $validated['persona_ids'],
            $validated['fecha_evento'],
            $validated['hora_inicio'],
            $validated['hora_fin'],
            $validated['exclude_id'] ?? null
        );

        return response()->json($resultado);
    }

    /**
     * Regla de exclusividad mutua: persona vs cliente externo.
     */
    private function validateExclusividadCliente(array $data): void
    {
        $persona = !empty($data['persona_id']);
        $externo = !empty($data['cliente_externo_id']);

        if ($persona === $externo) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cliente' => [$persona
                    ? 'Solo puede especificar un tipo de responsable, no ambos.'
                    : 'Debe especificar un responsable (persona o cliente externo).'
                ],
            ]);
        }
    }

    /**
     * Mapeo y serialización de salida con Eager Loading.
     */
    private function formatStreaming(ServicioStreaming $s): array
    {
        $cuenta = $s->relationLoaded('cuentaPorCobrar') ? $s->cuentaPorCobrar : null;
        $pagoRegistrado = $cuenta
            ? ($cuenta->monto_total > 0 && $cuenta->monto_abonado >= $cuenta->monto_total)
            : in_array($s->estado, ['confirmado', 'en_progreso', 'completado']);

        $asignaciones = $s->relationLoaded('asignacionesPersonal') && $s->asignacionesPersonal->isNotEmpty()
            ? $s->asignacionesPersonal->map(fn ($a) => [
                'id' => $a->id,
                'persona_id' => $a->persona_id,
                'rol' => $a->rol_en_servicio,
                'persona' => $a->relationLoaded('persona') && $a->persona
                    ? ['id' => $a->persona->id, 'nombres' => $a->persona->nombres, 'apellidos' => $a->persona->apellidos]
                    : null,
            ])->values()->toArray()
            : [];

        return [
            'id' => $s->id,
            'persona_id' => $s->persona_id,
            'cliente_externo_id' => $s->cliente_externo_id,
            'ciudad_id' => $s->ciudad_id,
            'ciudad' => $s->relationLoaded('ciudad') && $s->ciudad
                ? ['id' => $s->ciudad->id, 'nombre' => $s->ciudad->nombre]
                : null,
            'titulo' => $s->titulo,
            'fecha_evento' => $s->fecha_evento?->format('Y-m-d'),
            'hora_inicio' => $s->hora_inicio,
            'hora_fin' => $s->hora_fin,
            'lugar' => $s->lugar,
            'direccion' => $s->direccion,
            'referencias_ubicacion' => $s->referencias_ubicacion,
            'equipos_detalle' => $s->equipos_detalle,
            'descripcion' => $s->descripcion,
            'observaciones' => $s->observaciones,
            'precio_total' => (float) $s->precio_total,
            'precio_original' => $s->precio_original ? (float) $s->precio_original : null,
            'monto_descuento' => (float) ($s->monto_descuento ?? 0),
            'motivo_descuento' => $s->motivo_descuento,
            'monto_recargo' => (float) ($s->monto_recargo ?? 0),
            'motivo_recargo' => $s->motivo_recargo,
            'estado' => $s->estado,
            'pago_registrado' => $pagoRegistrado,
            'pago_abonado' => $cuenta ? ($cuenta->monto_abonado > 0) : false,
            'asignaciones' => $asignaciones,
            'persona' => $s->relationLoaded('persona') && $s->persona
                ? [
                    'id' => $s->persona->id,
                    'nombres' => $s->persona->nombres,
                    'apellidos' => $s->persona->apellidos,
                    'cedula' => $s->persona->cedula,
                    'correo' => $s->persona->correo,
                    'celular' => $s->persona->celular,
                ]
                : null,
            'cliente_externo' => $s->relationLoaded('clienteExterno') && $s->clienteExterno
                ? [
                    'id' => $s->clienteExterno->id,
                    'nombres' => $s->clienteExterno->nombres,
                    'apellidos' => $s->clienteExterno->apellidos,
                    'razon_social' => $s->clienteExterno->razon_social,
                    'ruc' => $s->clienteExterno->ruc,
                    'cedula' => $s->clienteExterno->cedula,
                    'correo' => $s->clienteExterno->correo,
                    'celular' => $s->clienteExterno->celular,
                ]
                : null,
            'created_at' => $s->created_at?->toISOString(),
            'cuenta_por_cobrar' => $cuenta
                ? [
                    'id' => $cuenta->id,
                    'monto_total' => (float) $cuenta->monto_total,
                    'monto_abonado' => (float) $cuenta->monto_abonado,
                    'saldo_pendiente' => (float) $cuenta->obtenerSaldoPendiente(),
                    'estado' => $cuenta->estado,
                    'transacciones' => $cuenta->relationLoaded('transacciones')
                        ? $cuenta->transacciones->map(fn ($t) => [
                            'id' => $t->id,
                            'monto' => (float) $t->monto,
                            'metodo_pago' => $t->metodo_pago,
                            'fecha_pago' => $t->fecha_pago?->format('Y-m-d') ?? (string) $t->fecha_pago,
                            'comprobante_url' => $t->comprobante_url,
                        ])->toArray()
                        : [],
                ]
                : null,
        ];
    }
}

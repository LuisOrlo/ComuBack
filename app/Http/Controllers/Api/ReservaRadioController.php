<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Services\ReservaRadio;
use App\Models\Services\TarifaRadio;
use App\Services\RadioConflictValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReservaRadioController extends Controller
{
    public function __construct(
        private readonly RadioConflictValidator $conflictValidator
    ) {}

    public function index(Request $request)
    {
        $query = ReservaRadio::with([
            'tarifa', 'persona', 'clienteExterno', 'operador',
        ]);

        if ($request->has('fecha')) {
            $query->where('fecha_reserva', $request->fecha);
        }

        if ($request->has('fecha_desde')) {
            $query->where('fecha_reserva', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->where('fecha_reserva', '<=', $request->fecha_hasta);
        }

        if ($request->has('tarifa_id')) {
            $query->where('tarifa_id', (int) $request->tarifa_id);
        }

        if ($request->has('operador_id')) {
            $query->where('operador_id', $request->operador_id);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('persona', fn ($pq) => $pq->where('nombres', 'ilike', "%{$search}%")
                    ->orWhere('apellidos', 'ilike', "%{$search}%"))
                  ->orWhereHas('clienteExterno', fn ($cq) => $cq->where('nombres', 'ilike', "%{$search}%")
                    ->orWhere('apellidos', 'ilike', "%{$search}%"));
            });
        }

        $reservas = $query->orderBy('fecha_reserva', 'desc')
            ->orderBy('hora_inicio')
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
        $validated = $request->validate([
            'tarifa_id' => 'required|integer|exists:tarifas_radio,id',
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'fecha_reserva' => 'required|date',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'incluye_operador' => 'boolean',
            'operador_id' => 'nullable|uuid|exists:personas,id',
            'observaciones' => 'nullable|string',
            'estado' => 'nullable|string|in:reservado,confirmado,en_progreso,completado,cancelado',
        ]);

        if (empty($validated['persona_id']) && empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Debe especificar un responsable (persona o cliente externo)'], 422);
        }

        if (!empty($validated['persona_id']) && !empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable, no ambos'], 422);
        }

        $incluyeOperador = $validated['incluye_operador'] ?? false;

        if ($incluyeOperador && empty($validated['operador_id'])) {
            return response()->json(['message' => 'Debe seleccionar un operador cuando incluye operador'], 422);
        }

        // Validar disponibilidad del espacio
        $espacioValido = $this->conflictValidator->validarDisponibilidadEspacio(
            $validated['fecha_reserva'],
            $validated['hora_inicio'],
            $validated['hora_fin'],
        );

        if (!$espacioValido['valido']) {
            return response()->json([
                'message' => 'El espacio de radio ya está reservado en el horario seleccionado.',
                'conflicto' => $espacioValido['conflicto'],
            ], 422);
        }

        // Validar disponibilidad del operador
        if ($incluyeOperador && !empty($validated['operador_id'])) {
            $operadorValido = $this->conflictValidator->validarDisponibilidadOperador(
                $validated['operador_id'],
                $validated['fecha_reserva'],
                $validated['hora_inicio'],
                $validated['hora_fin'],
            );

            if (!$operadorValido['valido']) {
                return response()->json([
                    'message' => 'El operador seleccionado ya tiene una reserva en ese horario.',
                    'conflicto' => $operadorValido['conflicto'],
                ], 422);
            }
        }

        // Calcular precio automáticamente
        $precioTotal = $this->conflictValidator->calcularPrecioTotal(
            (int) $validated['tarifa_id'],
            $validated['hora_inicio'],
            $validated['hora_fin'],
        );

        $data = [
            'tarifa_id' => (int) $validated['tarifa_id'],
            'persona_id' => $validated['persona_id'] ?? null,
            'cliente_externo_id' => $validated['cliente_externo_id'] ?? null,
            'fecha_reserva' => $validated['fecha_reserva'],
            'hora_inicio' => $validated['hora_inicio'],
            'hora_fin' => $validated['hora_fin'],
            'incluye_operador' => $incluyeOperador,
            'operador_id' => $validated['operador_id'] ?? null,
            'precio_total' => $precioTotal,
            'observaciones' => $validated['observaciones'] ?? null,
            'estado' => $validated['estado'] ?? 'reservado',
        ];

        $reserva = ReservaRadio::create($data);

        return response()->json([
            'message' => 'Reserva creada exitosamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'tarifa', 'persona', 'clienteExterno', 'operador',
            ])),
        ], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $reserva = ReservaRadio::with([
            'tarifa', 'persona', 'clienteExterno', 'operador',
        ])->findOrFail($id);

        return response()->json(['data' => $this->formatReserva($reserva)]);
    }

    public function update(Request $request, $id)
    {
        $reserva = ReservaRadio::findOrFail($id);

        \Illuminate\Support\Facades\Log::debug('reservas-radio.update request', ['id' => $id, 'input' => $request->all(), 'content_type' => $request->header('Content-Type')]);

        try {
            $validated = $request->validate([
                'tarifa_id' => 'sometimes|integer|exists:tarifas_radio,id',
                'persona_id' => 'nullable|uuid|exists:personas,id',
                'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
                'fecha_reserva' => 'sometimes|date',
                'hora_inicio' => 'sometimes|date_format:H:i',
                'hora_fin' => 'sometimes|date_format:H:i|after:hora_inicio',
                'incluye_operador' => 'boolean',
                'operador_id' => 'nullable|uuid|exists:personas,id',
                'observaciones' => 'nullable|string',
                'estado' => 'sometimes|string|in:reservado,confirmado,en_progreso,completado,cancelado',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::debug('reservas-radio.update validation failed', ['id' => $id, 'errors' => $e->errors(), 'input' => $request->all()]);
            throw $e;
        }

        $data = [];
        if (isset($validated['tarifa_id'])) $data['tarifa_id'] = (int) $validated['tarifa_id'];
        if (array_key_exists('persona_id', $validated)) $data['persona_id'] = $validated['persona_id'];
        if (array_key_exists('cliente_externo_id', $validated)) $data['cliente_externo_id'] = $validated['cliente_externo_id'];
        if (isset($validated['fecha_reserva'])) $data['fecha_reserva'] = $validated['fecha_reserva'];
        if (isset($validated['hora_inicio'])) $data['hora_inicio'] = $validated['hora_inicio'];
        if (isset($validated['hora_fin'])) $data['hora_fin'] = $validated['hora_fin'];
        if (array_key_exists('incluye_operador', $validated)) $data['incluye_operador'] = $validated['incluye_operador'];
        if (array_key_exists('operador_id', $validated)) $data['operador_id'] = $validated['operador_id'];
        if (array_key_exists('observaciones', $validated)) $data['observaciones'] = $validated['observaciones'];
        if (isset($validated['estado'])) $data['estado'] = $validated['estado'];

        $fecha = $data['fecha_reserva'] ?? $reserva->fecha_reserva->format('Y-m-d');
        $horaInicio = $data['hora_inicio'] ?? $reserva->hora_inicio;
        $horaFin = $data['hora_fin'] ?? $reserva->hora_fin;
        $incluyeOperador = $data['incluye_operador'] ?? $reserva->incluye_operador;
        $operadorId = $data['operador_id'] ?? $reserva->operador_id;

        // Validar espacio si cambió fecha/hora
        if (isset($data['fecha_reserva']) || isset($data['hora_inicio']) || isset($data['hora_fin'])) {
            $espacioValido = $this->conflictValidator->validarDisponibilidadEspacio(
                $fecha, $horaInicio, $horaFin, $reserva->id
            );

            if (!$espacioValido['valido']) {
                return response()->json([
                    'message' => 'El espacio de radio ya está reservado en el horario seleccionado.',
                    'conflicto' => $espacioValido['conflicto'],
                ], 422);
            }
        }

        // Validar operador si cambió
        if ($incluyeOperador && $operadorId && (
            isset($data['fecha_reserva']) || isset($data['hora_inicio']) ||
            isset($data['hora_fin']) || array_key_exists('operador_id', $validated)
        )) {
            $operadorValido = $this->conflictValidator->validarDisponibilidadOperador(
                $operadorId, $fecha, $horaInicio, $horaFin, $reserva->id
            );

            if (!$operadorValido['valido']) {
                return response()->json([
                    'message' => 'El operador seleccionado ya tiene una reserva en ese horario.',
                    'conflicto' => $operadorValido['conflicto'],
                ], 422);
            }
        }

        // Recalcular precio si cambió tarifa u horario
        $tarifaId = $data['tarifa_id'] ?? $reserva->tarifa_id;
        if (isset($data['tarifa_id']) || isset($data['hora_inicio']) || isset($data['hora_fin'])) {
            $data['precio_total'] = $this->conflictValidator->calcularPrecioTotal(
                (int) $tarifaId, $horaInicio, $horaFin
            );
        }

        $reserva->update($data);

        return response()->json([
            'message' => 'Reserva actualizada exitosamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'tarifa', 'persona', 'clienteExterno', 'operador',
            ])),
        ]);
    }

    public function destroy($id)
    {
        $reserva = ReservaRadio::findOrFail($id);
        $reserva->asignacionesPersonal()->delete();
        $reserva->delete();

        return response()->json([
            'message' => 'Reserva eliminada exitosamente.',
        ]);
    }

    public function cambiarEstado(Request $request, $id)
    {
        $reserva = ReservaRadio::findOrFail($id);

        $validated = $request->validate([
            'estado' => 'required|string|in:reservado,confirmado,en_progreso,completado,cancelado',
        ]);

        $nuevoEstado = $validated['estado'];
        $actual = $reserva->estado;

        $transicionesPermitidas = [
            'reservado' => ['confirmado', 'cancelado'],
            'confirmado' => ['en_progreso', 'cancelado'],
            'en_progreso' => ['completado', 'cancelado'],
            'completado' => [],
            'cancelado' => [],
        ];

        if (!in_array($nuevoEstado, $transicionesPermitidas[$actual] ?? [])) {
            return response()->json([
                'message' => "No se puede cambiar el estado de '{$actual}' a '{$nuevoEstado}'.",
            ], 422);
        }

        $reserva->update(['estado' => $nuevoEstado]);

        return response()->json([
            'message' => 'Estado actualizado exitosamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'tarifa', 'persona', 'clienteExterno', 'operador',
            ])),
        ]);
    }

    public function asignarOperador(Request $request, $id)
    {
        $reserva = ReservaRadio::findOrFail($id);

        $validated = $request->validate([
            'operador_id' => 'nullable|uuid|exists:personas,id',
        ]);

        $operadorId = $validated['operador_id'];

        if ($operadorId) {
            $operadorValido = $this->conflictValidator->validarDisponibilidadOperador(
                $operadorId,
                $reserva->fecha_reserva->format('Y-m-d'),
                $reserva->hora_inicio,
                $reserva->hora_fin,
                $reserva->id
            );

            if (!$operadorValido['valido']) {
                return response()->json([
                    'message' => 'El operador seleccionado ya tiene una reserva en ese horario.',
                    'conflicto' => $operadorValido['conflicto'],
                ], 422);
            }
        }

        $reserva->update([
            'operador_id' => $operadorId,
            'incluye_operador' => $operadorId !== null,
        ]);

        return response()->json([
            'message' => $operadorId ? 'Operador asignado exitosamente.' : 'Operador removido exitosamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'tarifa', 'persona', 'clienteExterno', 'operador',
            ])),
        ]);
    }

    public function registrarPago($id)
    {
        $reserva = ReservaRadio::findOrFail($id);

        if ($reserva->estado === 'cancelado') {
            return response()->json(['message' => 'No se puede registrar pago de una reserva cancelada'], 422);
        }

        if ($reserva->estado === 'completado') {
            return response()->json(['message' => 'La reserva ya está completada'], 422);
        }

        $reserva->update(['estado' => 'confirmado']);

        return response()->json([
            'message' => 'Pago registrado exitosamente.',
            'data' => $this->formatReserva($reserva->fresh()->load([
                'tarifa', 'persona', 'clienteExterno', 'operador',
            ])),
        ]);
    }

    public function disponibles(Request $request)
    {
        $validated = $request->validate([
            'fecha' => 'required|date',
        ]);

        $ocupados = ReservaRadio::where('fecha_reserva', $validated['fecha'])
            ->where('estado', '!=', 'cancelado')
            ->get(['hora_inicio', 'hora_fin']);

        $bloques = [];
        $horaApertura = 7;
        $horaCierre = 21;

        for ($h = $horaApertura; $h < $horaCierre; $h++) {
            $inicio = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            $fin = str_pad($h + 1, 2, '0', STR_PAD_LEFT) . ':00';

            $ocupado = $ocupados->contains(function ($o) use ($inicio, $fin) {
                return $o->hora_inicio < $fin && $o->hora_fin > $inicio;
            });

            $bloques[] = [
                'hora_inicio' => $inicio,
                'hora_fin' => $fin,
                'disponible' => !$ocupado,
            ];
        }

        return response()->json(['data' => $bloques]);
    }

    public function historial(Request $request)
    {
        $query = ReservaRadio::with([
            'tarifa', 'persona', 'clienteExterno', 'operador',
        ])->where('estado', '!=', 'reservado');

        if ($request->has('fecha_desde')) {
            $query->where('fecha_reserva', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->where('fecha_reserva', '<=', $request->fecha_hasta);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        $reservas = $query->orderBy('fecha_reserva', 'desc')
            ->orderBy('hora_inicio')
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

    private function formatReserva(ReservaRadio $r): array
    {
        $tarifa = null;
        if ($r->relationLoaded('tarifa') && $r->tarifa) {
            $t = $r->tarifa;
            $tarifa = [
                'id' => (string) $t->id,
                'nombre' => $t->nombre,
                'precio_por_hora' => (float) $t->precio_por_hora,
                'incluye_operador' => $t->incluye_operador,
            ];
        }

        return [
            'id' => $r->id,
            'tarifa_id' => (string) $r->tarifa_id,
            'persona_id' => $r->persona_id,
            'cliente_externo_id' => $r->cliente_externo_id,
            'fecha_reserva' => $r->fecha_reserva?->format('Y-m-d'),
            'hora_inicio' => $r->hora_inicio,
            'hora_fin' => $r->hora_fin,
            'incluye_operador' => $r->incluye_operador,
            'operador_id' => $r->operador_id,
            'precio_total' => (float) $r->precio_total,
            'estado' => $r->estado,
            'observaciones' => $r->observaciones,
            'tarifa' => $tarifa,
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
            'operador' => $r->relationLoaded('operador') && $r->operador
                ? ['id' => $r->operador->id, 'nombres' => $r->operador->nombres, 'apellidos' => $r->operador->apellidos]
                : null,
            'created_at' => $r->created_at?->toISOString(),
            'updated_at' => $r->updated_at?->toISOString(),
        ];
    }
}

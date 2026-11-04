<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\Services\ReservaSet;
use App\Models\Services\Set;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReservaSetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ReservaSet::with(['set', 'persona', 'clienteExterno', 'cuentaPorCobrar']);

        if ($request->filled('set_id')) {
            $query->where('set_id', $request->set_id);
        }

        if ($request->filled('fecha_inicio')) {
            $query->where('fecha_reserva', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->where('fecha_reserva', '<=', $request->fecha_fin);
        }

        if ($request->filled('estado') && $request->estado !== 'todos') {
            $query->where('estado', $request->estado);
        }

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('persona', function ($pq) use ($search) {
                    $pq->where('nombres', 'ilike', "%{$search}%")
                       ->orWhere('apellidos', 'ilike', "%{$search}%")
                       ->orWhere('cedula', 'ilike', "%{$search}%");
                })->orWhereHas('clienteExterno', function ($cq) use ($search) {
                    $cq->where('nombres', 'ilike', "%{$search}%")
                       ->orWhere('apellidos', 'ilike', "%{$search}%")
                       ->orWhere('cedula', 'ilike', "%{$search}%");
                })->orWhereHas('set', function ($sq) use ($search) {
                    $sq->where('nombre', 'ilike', "%{$search}%");
                });
            });
        }

        $reservas = $query->orderBy('fecha_reserva', 'desc')
                          ->orderBy('hora_inicio', 'asc')
                          ->get();

        return response()->json(['data' => $reservas]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'set_id' => 'required|uuid|exists:sets,id',
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'fecha_reserva' => 'required|date',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'precio_total' => 'required|numeric|min:0',
            'precio_original' => 'nullable|numeric|min:0',
            'monto_descuento' => 'nullable|numeric|min:0',
            'motivo_descuento' => 'nullable|string|max:255',
            'monto_recargo' => 'nullable|numeric|min:0',
            'motivo_recargo' => 'nullable|string|max:255',
            'estado' => 'nullable|string|in:reservado,confirmado,en_progreso,completado,cancelado',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        // Responsable exclusivo: exactamente uno presente
        if (empty($validated['persona_id']) && empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Debe especificar un responsable (estudiante o cliente externo).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!empty($validated['persona_id']) && !empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable, no ambos.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!isset($validated['estado'])) {
            $validated['estado'] = 'reservado';
        }

        $reserva = DB::transaction(function () use ($validated) {
            // Bloqueo del set para serializar validaciones de solapamiento
            Set::whereKey($validated['set_id'])->lockForUpdate()->firstOrFail();

            $conflicto = ReservaSet::where('set_id', $validated['set_id'])
                ->where('fecha_reserva', $validated['fecha_reserva'])
                ->where('estado', '!=', 'cancelado')
                ->where('hora_inicio', '<', $validated['hora_fin'])
                ->where('hora_fin', '>', $validated['hora_inicio'])
                ->lockForUpdate()
                ->exists();

            if ($conflicto) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'El set ya se encuentra reservado en el horario seleccionado.');
            }

            $reserva = ReservaSet::create($validated);

            CuentaPorCobrar::create([
                'reserva_set_id' => $reserva->id,
                'monto_total' => $validated['precio_total'],
                'monto_abonado' => 0,
                'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
                'es_legacy' => false,
            ]);

            return $reserva;
        });

        Log::channel('audit')->info('reserva_set.creada', [
            'reserva_id' => $reserva->id,
            'usuario_id' => auth()->id(),
            'ip' => request()->ip(),
        ]);

        return response()->json([
            'message' => 'Reserva de set creada exitosamente.',
            'data' => $reserva->load(['set', 'persona', 'clienteExterno', 'cuentaPorCobrar']),
        ], Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $reserva = ReservaSet::with(['set', 'persona', 'clienteExterno', 'cuentaPorCobrar'])->findOrFail($id);
        return response()->json(['data' => $reserva]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $reserva = ReservaSet::findOrFail($id);

        $validated = $request->validate([
            'set_id' => 'sometimes|uuid|exists:sets,id',
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'fecha_reserva' => 'sometimes|date',
            'hora_inicio' => 'sometimes|date_format:H:i',
            'hora_fin' => 'sometimes|date_format:H:i|after:hora_inicio',
            'precio_total' => 'sometimes|numeric|min:0',
            'precio_original' => 'nullable|numeric|min:0',
            'monto_descuento' => 'nullable|numeric|min:0',
            'motivo_descuento' => 'nullable|string|max:255',
            'monto_recargo' => 'nullable|numeric|min:0',
            'motivo_recargo' => 'nullable|string|max:255',
            'estado' => 'sometimes|string|in:reservado,confirmado,en_progreso,completado,cancelado',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $data = $validated;

        $personaId = $data['persona_id'] ?? $reserva->persona_id;
        $clienteExternoId = array_key_exists('cliente_externo_id', $data)
            ? $data['cliente_externo_id']
            : $reserva->cliente_externo_id;

        if (empty($personaId) && empty($clienteExternoId)) {
            return response()->json(['message' => 'Debe especificar un responsable.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!empty($personaId) && !empty($clienteExternoId)) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable, no ambos.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return DB::transaction(function () use ($reserva, $data) {
            $setId = $data['set_id'] ?? $reserva->set_id;
            Set::whereKey($setId)->lockForUpdate()->firstOrFail();

            if (isset($data['set_id']) || isset($data['fecha_reserva']) || isset($data['hora_inicio']) || isset($data['hora_fin']) || (isset($data['estado']) && $data['estado'] !== 'cancelado')) {
                $fecha = $data['fecha_reserva'] ?? $reserva->fecha_reserva;
                $horaInicio = $data['hora_inicio'] ?? $reserva->hora_inicio;
                $horaFin = $data['hora_fin'] ?? $reserva->hora_fin;
                $estado = $data['estado'] ?? $reserva->estado;

                if ($estado !== 'cancelado') {
                    $conflicto = ReservaSet::where('set_id', $setId)
                        ->where('fecha_reserva', $fecha)
                        ->where('id', '!=', $reserva->id)
                        ->where('estado', '!=', 'cancelado')
                        ->where('hora_inicio', '<', $horaFin)
                        ->where('hora_fin', '>', $horaInicio)
                        ->lockForUpdate()
                        ->exists();

                    if ($conflicto) {
                        abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'El set ya se encuentra reservado en el horario seleccionado.');
                    }
                }
            }

            // Sincronizar cuenta por cobrar si cambió precio_total
            if (isset($data['precio_total'])) {
                $cuenta = CuentaPorCobrar::where('reserva_set_id', $reserva->id)->first();
                if ($cuenta) {
                    if ((float) $cuenta->monto_abonado > (float) $data['precio_total']) {
                        abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'El monto total no puede ser menor al monto ya abonado.');
                    }

                    if ((float) $cuenta->monto_total !== (float) $data['precio_total']) {
                        $saldo = (float) $data['precio_total'] - (float) $cuenta->monto_abonado;
                        $cuenta->update([
                            'monto_total' => $data['precio_total'],
                            'estado' => $saldo <= 0
                                ? CuentaPorCobrar::ESTADO_PAGADO
                                : ((float) $cuenta->monto_abonado > 0 ? CuentaPorCobrar::ESTADO_ABONADO : CuentaPorCobrar::ESTADO_PENDIENTE),
                        ]);
                    }
                }
            }

            $reserva->update($data);

            Log::channel('audit')->info('reserva_set.actualizada', [
                'reserva_id' => $reserva->id,
                'usuario_id' => auth()->id(),
                'ip' => request()->ip(),
            ]);

            return response()->json([
                'message' => 'Reserva de set actualizada exitosamente.',
                'data' => $reserva->fresh(['set', 'persona', 'clienteExterno', 'cuentaPorCobrar']),
            ]);
        });
    }

    public function destroy(string $id): JsonResponse
    {
        $bloqueadaPorPago = false;
        $reservaId = null;

        DB::transaction(function () use ($id, &$bloqueadaPorPago, &$reservaId) {
            $reserva = ReservaSet::whereKey($id)->lockForUpdate()->firstOrFail();
            $reservaId = $reserva->id;
            $cuenta = CuentaPorCobrar::where('reserva_set_id', $reserva->id)->lockForUpdate()->first();

            if ($cuenta && $cuenta->transacciones()->exists()) {
                $bloqueadaPorPago = true;
                return;
            }

            $cuenta?->delete();
            $reserva->delete();
        });

        if ($bloqueadaPorPago) {
            return response()->json([
                'message' => 'No se puede eliminar la reserva porque tiene pagos o comprobantes registrados en finanzas.',
            ], Response::HTTP_CONFLICT);
        }

        Log::channel('audit')->info('reserva_set.eliminada', [
            'reserva_id' => $reservaId,
            'usuario_id' => auth()->id(),
            'ip' => request()->ip(),
        ]);

        return response()->json([
            'message' => 'Reserva eliminada exitosamente.',
        ]);
    }
}

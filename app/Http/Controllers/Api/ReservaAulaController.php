<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\Services\ReservaAula;
use App\Models\Services\Aula;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReservaAulaController extends Controller
{
    public function index(Request $request)
    {
        $query = ReservaAula::with(['aula', 'persona', 'clienteExterno', 'cuentaPorCobrar']);

        if ($request->has('aula_id')) {
            $query->where('aula_id', $request->aula_id);
        }

        if ($request->has('fecha')) {
            $query->where('fecha_reserva', $request->fecha);
        }

        if ($request->has('fecha_inicio') && $request->has('fecha_fin')) {
            $query->whereBetween('fecha_reserva', [$request->fecha_inicio, $request->fecha_fin]);
        } elseif ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
            $query->whereBetween('fecha_reserva', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $perPage = $request->get('per_page', 15);
        if ($perPage === 'all' || $request->boolean('all')) {
            $reservas = $query->orderBy('fecha_reserva')->orderBy('hora_inicio')->get();
            return response()->json([
                'data' => $reservas,
                'meta' => [
                    'total' => $reservas->count(),
                    'all' => true,
                ],
            ]);
        }

        $reservas = $query->orderBy('fecha_reserva')->orderBy('hora_inicio')
            ->paginate((int) $perPage);

        return response()->json([
            'data' => $reservas->items(),
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
            'aula_id' => 'required|uuid|exists:aulas,id',
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
            'estado' => 'nullable|string|in:reservado,confirmado,en_progreso,completado,cancelado'
        ]);

        // Asegurar que solo uno (persona o cliente externo) esté presente
        if (empty($validated['persona_id']) && empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Debe especificar un responsable (persona o cliente externo)'], 422);
        }

        if (!empty($validated['persona_id']) && !empty($validated['cliente_externo_id'])) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable, no ambos'], 422);
        }

        // Validar disponibilidad
        $conflicto = ReservaAula::where('aula_id', $validated['aula_id'])
            ->where('fecha_reserva', $validated['fecha_reserva'])
            ->where('estado', '!=', 'cancelado')
            ->where(function($q) use ($validated) {
                $q->where(function($q2) use ($validated) {
                    $q2->where('hora_inicio', '<', $validated['hora_fin'])
                       ->where('hora_fin', '>', $validated['hora_inicio']);
                });
            })->exists();

        if ($conflicto) {
            return response()->json(['message' => 'El aula ya está reservada en el horario seleccionado'], 422);
        }

        if (!isset($validated['estado'])) {
            $validated['estado'] = 'reservado';
        }

        $reserva = DB::transaction(function () use ($validated) {
            // Bloquear el recurso padre hace que la comprobación sea segura aun
            // cuando todavía no existan reservas previas para esa aula.
            Aula::whereKey($validated['aula_id'])->lockForUpdate()->firstOrFail();
            // Bloqueo para evitar dos reservas simultáneas del mismo horario.
            $conflicto = ReservaAula::where('aula_id', $validated['aula_id'])
                ->where('fecha_reserva', $validated['fecha_reserva'])
                ->where('estado', '!=', 'cancelado')
                ->where('hora_inicio', '<', $validated['hora_fin'])
                ->where('hora_fin', '>', $validated['hora_inicio'])
                ->lockForUpdate()->exists();
            if ($conflicto) {
                abort(422, 'El aula ya está reservada en el horario seleccionado');
            }
            $reserva = ReservaAula::create($validated);
            CuentaPorCobrar::create([
                'reserva_aula_id' => $reserva->id,
                'monto_total' => $validated['precio_total'],
                'monto_abonado' => 0,
                'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
                'es_legacy' => false,
            ]);
            return $reserva;
        });
        Log::channel('audit')->info('reserva_aula.creada', ['reserva_id' => $reserva->id, 'usuario_id' => auth()->id(), 'ip' => request()->ip()]);

        return response()->json([
            'message' => 'Reserva creada exitosamente.',
            'data' => $reserva->load(['aula', 'persona', 'clienteExterno', 'cuentaPorCobrar'])
        ], Response::HTTP_CREATED);
    }

    public function storeBatch(Request $request)
    {
        $validated = $request->validate([
            'persona_id' => 'nullable|uuid|exists:personas,id',
            'cliente_externo_id' => 'nullable|uuid|exists:clientes_externos,id',
            'reservas' => 'required|array|min:1',
            'reservas.*.aula_id' => 'required|uuid|exists:aulas,id',
            'reservas.*.fecha_reserva' => 'required|date',
            'reservas.*.hora_inicio' => 'required|date_format:H:i',
            'reservas.*.hora_fin' => 'required|date_format:H:i|after:reservas.*.hora_inicio',
            'reservas.*.precio_total' => 'required|numeric|min:0',
            'reservas.*.precio_original' => 'nullable|numeric|min:0',
            'reservas.*.monto_descuento' => 'nullable|numeric|min:0',
            'reservas.*.motivo_descuento' => 'nullable|string|max:255',
            'reservas.*.monto_recargo' => 'nullable|numeric|min:0',
            'reservas.*.motivo_recargo' => 'nullable|string|max:255',
            'reservas.*.estado' => 'nullable|string|in:reservado,confirmado,en_progreso,completado,cancelado',
        ]);

        $this->validateBatchResponsible($validated);
        $this->validateAulaBatchConflicts($validated['reservas']);

        $created = DB::transaction(function () use ($validated) {
            $aulaIds = collect($validated['reservas'])
                ->pluck('aula_id')->unique()->sort()->values();

            foreach ($aulaIds as $aulaId) {
                Aula::whereKey($aulaId)->lockForUpdate()->firstOrFail();
            }

            foreach ($validated['reservas'] as $index => $item) {
                $conflicto = ReservaAula::where('aula_id', $item['aula_id'])
                    ->where('fecha_reserva', $item['fecha_reserva'])
                    ->where('estado', '!=', 'cancelado')
                    ->where('hora_inicio', '<', $item['hora_fin'])
                    ->where('hora_fin', '>', $item['hora_inicio'])
                    ->lockForUpdate()->exists();

                if ($conflicto) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "reservas.$index.hora_inicio" => 'El aula ya está reservada en el horario seleccionado.',
                    ]);
                }
            }

            $result = [];
            foreach ($validated['reservas'] as $item) {
                $data = [
                    'aula_id' => $item['aula_id'],
                    'persona_id' => $validated['persona_id'] ?? null,
                    'cliente_externo_id' => $validated['cliente_externo_id'] ?? null,
                    'fecha_reserva' => $item['fecha_reserva'],
                    'hora_inicio' => $item['hora_inicio'],
                    'hora_fin' => $item['hora_fin'],
                    'precio_total' => $item['precio_total'],
                    'precio_original' => $item['precio_original'] ?? null,
                    'monto_descuento' => $item['monto_descuento'] ?? 0,
                    'motivo_descuento' => $item['motivo_descuento'] ?? null,
                    'monto_recargo' => $item['monto_recargo'] ?? 0,
                    'motivo_recargo' => $item['motivo_recargo'] ?? null,
                    'estado' => $item['estado'] ?? 'reservado',
                ];

                $reserva = ReservaAula::create($data);
                CuentaPorCobrar::create([
                    'reserva_aula_id' => $reserva->id,
                    'monto_total' => $data['precio_total'],
                    'monto_abonado' => 0,
                    'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
                    'es_legacy' => false,
                ]);
                $result[] = $reserva->load(['aula', 'persona', 'clienteExterno', 'cuentaPorCobrar']);
            }

            return $result;
        });

        return response()->json([
            'message' => 'Reservas creadas exitosamente.',
            'data' => $created,
        ], Response::HTTP_CREATED);
    }

    private function validateBatchResponsible(array $validated): void
    {
        $persona = !empty($validated['persona_id']);
        $externo = !empty($validated['cliente_externo_id']);

        if ($persona === $externo) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cliente' => [$persona ? 'Solo puede especificar un tipo de responsable, no ambos.' : 'Debe especificar un responsable (persona o cliente externo).'],
            ]);
        }
    }

    private function validateAulaBatchConflicts(array $reservas): void
    {
        foreach ($reservas as $i => $actual) {
            foreach ($reservas as $j => $otra) {
                if ($i >= $j || $actual['aula_id'] !== $otra['aula_id'] || $actual['fecha_reserva'] !== $otra['fecha_reserva']) {
                    continue;
                }

                if ($actual['hora_inicio'] < $otra['hora_fin'] && $actual['hora_fin'] > $otra['hora_inicio']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "reservas.$j.hora_inicio" => "El horario entra en conflicto con la reserva ".($i + 1)." del mismo lote.",
                    ]);
                }
            }
        }
    }

    public function show($id)
    {
        $reserva = ReservaAula::with(['aula', 'persona', 'clienteExterno', 'cuentaPorCobrar'])->findOrFail($id);
        return response()->json(['data' => $reserva]);
    }

    public function update(Request $request, $id)
    {
        $reserva = ReservaAula::findOrFail($id);

        $validated = $request->validate([
            'aula_id' => 'sometimes|uuid|exists:aulas,id',
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
            'estado' => 'sometimes|string|in:reservado,confirmado,en_progreso,completado,cancelado'
        ]);

        $data = [];
        if (isset($validated['aula_id'])) $data['aula_id'] = $validated['aula_id'];
        if (array_key_exists('persona_id', $validated)) $data['persona_id'] = $validated['persona_id'];
        if (array_key_exists('cliente_externo_id', $validated)) $data['cliente_externo_id'] = $validated['cliente_externo_id'];
        if (isset($validated['fecha_reserva'])) $data['fecha_reserva'] = $validated['fecha_reserva'];
        if (isset($validated['hora_inicio'])) $data['hora_inicio'] = $validated['hora_inicio'];
        if (isset($validated['hora_fin'])) $data['hora_fin'] = $validated['hora_fin'];
        if (isset($validated['precio_total'])) $data['precio_total'] = $validated['precio_total'];
        if (array_key_exists('precio_original', $validated)) $data['precio_original'] = $validated['precio_original'];
        if (array_key_exists('monto_descuento', $validated)) $data['monto_descuento'] = $validated['monto_descuento'];
        if (array_key_exists('motivo_descuento', $validated)) $data['motivo_descuento'] = $validated['motivo_descuento'];
        if (array_key_exists('monto_recargo', $validated)) $data['monto_recargo'] = $validated['monto_recargo'];
        if (array_key_exists('motivo_recargo', $validated)) $data['motivo_recargo'] = $validated['motivo_recargo'];
        if (isset($validated['estado'])) $data['estado'] = $validated['estado'];

        if (isset($data['estado']) && $data['estado'] !== $reserva->estado) {
            $transiciones = [
                'reservado' => ['confirmado', 'cancelado'],
                'confirmado' => ['en_progreso', 'cancelado'],
                'en_progreso' => ['completado', 'cancelado'],
                'completado' => [],
                'cancelado' => [],
            ];
            if (!in_array($data['estado'], $transiciones[$reserva->estado] ?? [], true)) {
                return response()->json(['message' => 'Transición de estado no permitida: '.$reserva->estado.' → '.$data['estado']], 422);
            }
        }

        // Asegurar que solo uno (persona o cliente externo) esté presente
        $personaId = $data['persona_id'] ?? $reserva->persona_id;
        $clienteExternoId = array_key_exists('cliente_externo_id', $data)
            ? $data['cliente_externo_id']
            : $reserva->cliente_externo_id;

        if (empty($personaId) && empty($clienteExternoId)) {
            return response()->json(['message' => 'Debe especificar un responsable (persona o cliente externo)'], 422);
        }

        if (!empty($personaId) && !empty($clienteExternoId)) {
            return response()->json(['message' => 'Solo puede especificar un tipo de responsable, no ambos'], 422);
        }

        return DB::transaction(function () use ($reserva, $data, $request) {
            $aulaId = $data['aula_id'] ?? $reserva->aula_id;
            Aula::whereKey($aulaId)->lockForUpdate()->firstOrFail();

            // Validar disponibilidad si cambió aula, fecha u horario (excluyendo esta reserva)
            if (isset($data['aula_id']) || isset($data['fecha_reserva']) || isset($data['hora_inicio']) || isset($data['hora_fin']) || (isset($data['estado']) && $data['estado'] !== 'cancelado')) {
                $fecha = $data['fecha_reserva'] ?? $reserva->fecha_reserva;
                $horaInicio = $data['hora_inicio'] ?? $reserva->hora_inicio;
                $horaFin = $data['hora_fin'] ?? $reserva->hora_fin;
                $estado = $data['estado'] ?? $reserva->estado;

                if ($estado !== 'cancelado') {
                    $conflicto = ReservaAula::where('aula_id', $aulaId)
                        ->where('fecha_reserva', $fecha)
                        ->where('id', '!=', $reserva->id)
                        ->where('estado', '!=', 'cancelado')
                        ->where(function ($q) use ($horaInicio, $horaFin) {
                            $q->where('hora_inicio', '<', $horaFin)
                               ->where('hora_fin', '>', $horaInicio);
                        })
                        ->lockForUpdate()
                        ->exists();

                    if ($conflicto) {
                        abort(422, 'El aula ya está reservada en el horario seleccionado');
                    }
                }
            }

            // Sincronizar la cuenta por cobrar si cambia el precio
            if (isset($data['precio_total'])) {
                $cuenta = CuentaPorCobrar::where('reserva_aula_id', $reserva->id)->first();

                if ($cuenta) {
                    if ((float) $cuenta->monto_abonado > (float) $data['precio_total']) {
                        abort(422, 'El monto total no puede ser menor al monto ya abonado ('.$cuenta->monto_abonado.')');
                    }

                    if ((float) $cuenta->monto_total !== (float) $data['precio_total']) {
                        $saldo = (float) $data['precio_total'] - (float) $cuenta->monto_abonado;

                        $cuenta->update([
                            'monto_total' => $data['precio_total'],
                            'estado' => $saldo <= 0 ? CuentaPorCobrar::ESTADO_PAGADO
                                : ((float) $cuenta->monto_abonado > 0 ? CuentaPorCobrar::ESTADO_ABONADO : CuentaPorCobrar::ESTADO_PENDIENTE),
                        ]);
                    }
                }
            }

            $reserva->update($data);
            Log::channel('audit')->info('reserva_aula.actualizada', ['reserva_id' => $reserva->id, 'cambios' => $reserva->getChanges(), 'usuario_id' => auth()->id(), 'ip' => $request->ip()]);

            return response()->json([
                'message' => 'Reserva de aula actualizada exitosamente',
                'data' => $reserva->fresh(['aula', 'persona', 'clienteExterno', 'cuentaPorCobrar'])
            ]);
        });
    }

    public function destroy($id)
    {
        $bloqueadaPorPago = false;
        $reservaId = null;

        DB::transaction(function () use ($id, &$bloqueadaPorPago, &$reservaId) {
            $reserva = ReservaAula::whereKey($id)->lockForUpdate()->firstOrFail();
            $reservaId = $reserva->id;
            $cuenta = CuentaPorCobrar::where('reserva_aula_id', $reserva->id)
                ->lockForUpdate()
                ->first();

            if ($cuenta && $cuenta->transacciones()->exists()) {
                $bloqueadaPorPago = true;
                return;
            }

            $cuenta?->delete();
            $reserva->delete();
        });

        if ($bloqueadaPorPago) {
            return response()->json([
                'message' => 'No se puede eliminar este registro porque tiene pagos registrados.',
            ], 409);
        }

        Log::channel('audit')->info('reserva_aula.eliminada', ['reserva_id' => $reservaId, 'usuario_id' => auth()->id(), 'ip' => request()->ip()]);

        return response()->json([
            'message' => 'Reserva eliminada exitosamente.'
        ]);
    }
}

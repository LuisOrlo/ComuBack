<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\Services\ReservaAula;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReservaAulaController extends Controller
{
    public function index(Request $request)
    {
        $query = ReservaAula::with(['aula', 'persona', 'clienteExterno']);

        if ($request->has('aula_id')) {
            $query->where('aula_id', $request->aula_id);
        }

        if ($request->has('fecha_inicio') && $request->has('fecha_fin')) {
            $query->whereBetween('fecha_reserva', [$request->fecha_inicio, $request->fecha_fin]);
        }

        $reservas = $query->orderBy('fecha_reserva')->orderBy('hora_inicio')
            ->paginate($request->get('per_page', 15));

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

        $reserva = ReservaAula::create($validated);

        CuentaPorCobrar::create([
            'reserva_aula_id' => $reserva->id,
            'monto_total' => $validated['precio_total'],
            'monto_abonado' => 0,
            'estado' => 'pendiente',
            'es_legacy' => false,
        ]);

        return response()->json([
            'message' => 'Reserva creada exitosamente.',
            'data' => $reserva->load(['aula', 'persona', 'clienteExterno'])
        ], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $reserva = ReservaAula::with(['aula', 'persona', 'clienteExterno'])->findOrFail($id);
        return response()->json(['data' => $reserva]);
    }

    public function update(Request $request, $id)
    {
        $reserva = ReservaAula::findOrFail($id);

        $validated = $request->validate([
            'estado' => 'required|string|in:reservado,confirmado,en_progreso,completado,cancelado'
        ]);

        $reserva->update($validated);

        return response()->json([
            'message' => 'Estado de reserva actualizado exitosamente.',
            'data' => $reserva->load(['aula', 'persona', 'clienteExterno'])
        ]);
    }

    public function destroy($id)
    {
        $reserva = ReservaAula::findOrFail($id);
        $reserva->delete();

        return response()->json([
            'message' => 'Reserva eliminada exitosamente.'
        ]);
    }
}

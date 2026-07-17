<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClienteExterno;
use App\Models\CuentaPorCobrar;
use App\Models\Services\AlquilerEquipo;
use App\Models\Services\ReservaAula;
use App\Models\Services\ReservaPodcast;
use App\Models\Services\ReservaRadio;
use App\Models\Services\TrabajoEdicion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClienteExternoController extends Controller
{
    /**
     * Lista clientes externos con busqueda.
     * Solo muestra registros marcados como cliente (es_cliente = true).
     * Estudiantes sin servicios contratados no aparecen.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ClienteExterno::query()->where('es_cliente', true);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombres', 'ilike', "%{$search}%")
                  ->orWhere('apellidos', 'ilike', "%{$search}%")
                  ->orWhere('cedula', 'ilike', "%{$search}%")
                  ->orWhere('correo', 'ilike', "%{$search}%")
                  ->orWhere('celular', 'ilike', "%{$search}%");
            });
        }

        $clientes = $query->orderBy('nombres')->paginate(
            perPage: $request->integer('per_page', 15),
            page: $request->integer('page', 1)
        );

        return response()->json($clientes);
    }

    /**
     * Crear cliente externo
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombres' => 'required|string|max:100',
            'apellidos' => 'nullable|string|max:100',
            'cedula' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:150',
            'celular' => 'nullable|string|max:20',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'ciudad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'ocupacion' => 'nullable|string|max:100',
            'estado_civil' => 'nullable|string|max:20',
            'fecha_nacimiento' => 'nullable|date',
            'observaciones' => 'nullable|string',
        ]);

        $cliente = ClienteExterno::create([...$validated, 'es_cliente' => true]);

        return response()->json(['data' => $cliente], 201);
    }

    /**
     * Ver detalle de cliente externo
     */
    public function show(string $id): JsonResponse
    {
        $cliente = ClienteExterno::findOrFail($id);
        return response()->json(['data' => $cliente]);
    }

    /**
     * Actualizar cliente externo
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $cliente = ClienteExterno::findOrFail($id);

        $validated = $request->validate([
            'nombres' => 'required|string|max:100',
            'apellidos' => 'nullable|string|max:100',
            'cedula' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:150',
            'celular' => 'nullable|string|max:20',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'observaciones' => 'nullable|string',
            'ciudad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'ocupacion' => 'nullable|string|max:100',
            'estado_civil' => 'nullable|string|max:20',
            'fecha_nacimiento' => 'nullable|date',
            'edad' => 'nullable|integer',
        ]);

        $cliente->update($validated);

        return response()->json(['data' => $cliente]);
    }

    /**
     * Eliminar cliente externo
     */
    public function destroy(string $id): JsonResponse
    {
        $cliente = ClienteExterno::findOrFail($id);
        $cliente->delete();
        return response()->json(['data' => null], 204);
    }

    /**
     * Buscar por cedula
     */
    public function buscarCedula(Request $request): JsonResponse
    {
        $request->validate(['cedula' => 'required|string|max:20']);

        $cliente = ClienteExterno::where('cedula', $request->cedula)->first();

        if (!$cliente) {
            return response()->json(['data' => null], 200);
        }

        return response()->json(['data' => $cliente]);
    }

    /**
     * Reservas del cliente (todos los servicios), optimizado con índices
     */
    public function reservas(string $id): JsonResponse
    {
        ClienteExterno::findOrFail($id);

        $radio = ReservaRadio::where('cliente_externo_id', $id)
            ->with('tarifa')
            ->orderBy('fecha_reserva', 'desc')
            ->orderBy('hora_inicio', 'desc')
            ->get();

        $aulas = ReservaAula::where('cliente_externo_id', $id)
            ->with('aula')
            ->orderBy('fecha_reserva', 'desc')
            ->orderBy('hora_inicio', 'desc')
            ->get();

        $podcast = ReservaPodcast::where('cliente_externo_id', $id)
            ->with('paquete')
            ->orderBy('fecha_reserva', 'desc')
            ->orderBy('hora_inicio', 'desc')
            ->get();

        $equipos = AlquilerEquipo::where('cliente_externo_id', $id)
            ->with('equipo')
            ->orderBy('fecha_entrega', 'desc')
            ->get();

        $edicion = TrabajoEdicion::where('cliente_externo_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => [
                'radio' => $radio,
                'aulas' => $aulas,
                'podcast' => $podcast,
                'equipos' => $equipos,
                'edicion' => $edicion,
            ],
        ]);
    }

    /**
     * Información financiera del cliente, optimizado: pluck + whereIn evita correlated subqueries
     */
    public function financial(string $id): JsonResponse
    {
        ClienteExterno::findOrFail($id);

        $radioIds = ReservaRadio::where('cliente_externo_id', $id)->pluck('id');
        $aulaIds = ReservaAula::where('cliente_externo_id', $id)->pluck('id');
        $podcastIds = ReservaPodcast::where('cliente_externo_id', $id)->pluck('id');
        $equipoIds = AlquilerEquipo::where('cliente_externo_id', $id)->pluck('id');
        $edicionIds = TrabajoEdicion::where('cliente_externo_id', $id)->pluck('id');

        $cuentas = CuentaPorCobrar::with('transacciones')
            ->where(function ($q) use ($radioIds, $aulaIds, $podcastIds, $equipoIds, $edicionIds) {
                if ($radioIds->isNotEmpty()) $q->orWhereIn('reserva_radio_id', $radioIds);
                if ($aulaIds->isNotEmpty()) $q->orWhereIn('reserva_aula_id', $aulaIds);
                if ($podcastIds->isNotEmpty()) $q->orWhereIn('reserva_podcast_id', $podcastIds);
                if ($equipoIds->isNotEmpty()) $q->orWhereIn('alquiler_equipo_id', $equipoIds);
                if ($edicionIds->isNotEmpty()) $q->orWhereIn('edicion_video_id', $edicionIds);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $cuentas]);
    }
}

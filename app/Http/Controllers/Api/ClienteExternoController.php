<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClienteExterno;
use App\Models\ClienteExternoContacto;
use App\Models\CuentaPorCobrar;
use App\Models\Services\AlquilerEquipo;
use App\Models\Services\ReservaAula;
use App\Models\Services\ReservaPodcast;
use App\Models\Services\ReservaRadio;
use App\Models\Services\TrabajoEdicion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ClienteExternoController extends Controller
{
    /**
     * Lista clientes externos con busqueda.
     * Solo muestra registros marcados como cliente (es_cliente = true).
     * Estudiantes sin servicios contratados no aparecen.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ClienteExterno::query()->where('es_cliente', true)->with('contactosActivos');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombres', 'ilike', "%{$search}%")
                  ->orWhere('nombre_empresa', 'ilike', "%{$search}%")
                  ->orWhere('apellidos', 'ilike', "%{$search}%")
                  ->orWhere('cedula', 'ilike', "%{$search}%")
                  ->orWhere('ruc', 'ilike', "%{$search}%")
                  ->orWhere('correo', 'ilike', "%{$search}%")
                  ->orWhere('celular', 'ilike', "%{$search}%")
                  ->orWhereHas('contactosActivos', function ($contacto) use ($search) {
                      $contacto->where('nombres', 'ilike', "%{$search}%")
                          ->orWhere('apellidos', 'ilike', "%{$search}%")
                          ->orWhere('correo', 'ilike', "%{$search}%")
                          ->orWhere('celular', 'ilike', "%{$search}%");
                  });
            });
        }

        $clientes = $query->orderByRaw("COALESCE(nombre_empresa, CONCAT_WS(' ', nombres, apellidos)) ASC")->paginate(
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
        $validated = $this->validatePayload($request, false);
        $tipo = $validated['tipo_cliente'];

        $cliente = DB::transaction(function () use ($validated, $tipo) {
            $contactos = $validated['contactos'] ?? [];
            unset($validated['contactos']);
            $validated = $this->normalizar($validated);
            $cliente = ClienteExterno::create([...$validated, 'es_cliente' => true]);
            if ($tipo === 'empresa') {
                $this->syncContactos($cliente, $contactos);
            }
            return $cliente->load('contactos');
        });

        return response()->json(['data' => $cliente], 201);
    }

    private function validatePayload(Request $request, bool $update): array
    {
        $tipo = $request->input('tipo_cliente', 'persona');
        $rules = [
            'tipo_cliente' => ['nullable', Rule::in(['persona', 'empresa'])],
            'nombres' => [$tipo === 'persona' ? 'required' : 'nullable', 'string', 'max:100'],
            'nombre_empresa' => [$tipo === 'empresa' ? 'required' : 'nullable', 'string', 'max:150'],
            'apellidos' => 'nullable|string|max:100',
            'cedula' => ['nullable', 'string', 'max:20'],
            'ruc' => ['nullable', 'string', 'max:20'],
            'correo' => ['nullable', 'email', 'max:150'],
            'celular' => 'nullable|string|max:20',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'ciudad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'ocupacion' => 'nullable|string|max:100',
            'estado_civil' => 'nullable|string|max:20',
            'observaciones' => 'nullable|string',
            'contactos' => [$tipo === 'empresa' ? 'required' : 'nullable', 'array', $tipo === 'empresa' ? 'min:1' : 'sometimes'],
            'contactos.*.id' => 'nullable|uuid',
            'contactos.*.nombres' => 'required|string|max:100',
            'contactos.*.apellidos' => 'nullable|string|max:100',
            'contactos.*.cargo' => 'nullable|string|max:100',
            'contactos.*.celular' => 'nullable|string|max:20',
            'contactos.*.correo' => 'nullable|email|max:150',
            'contactos.*.es_principal' => 'boolean',
            'contactos.*.activo' => 'boolean',
        ];

        if ($tipo === 'persona') {
            if ($update) {
                $clienteId = $request->route('id');
                $rules['cedula'][] = Rule::unique('pgsql.people.clientes_externos', 'cedula')->ignore($clienteId);
                $rules['correo'][] = Rule::unique('pgsql.people.clientes_externos', 'correo')->ignore($clienteId);
            } else {
                $rules['cedula'][] = 'unique:pgsql.people.clientes_externos,cedula';
                $rules['correo'][] = 'unique:pgsql.people.clientes_externos,correo';
            }
        } elseif ($tipo === 'empresa' && $request->filled('ruc')) {
            if ($update) {
                $clienteId = $request->route('id');
                $rules['ruc'][] = Rule::unique('pgsql.people.clientes_externos', 'ruc')->ignore($clienteId);
            } else {
                $rules['ruc'][] = 'unique:pgsql.people.clientes_externos,ruc';
            }
        }

        $validated = $request->validate($rules);
        $validated['tipo_cliente'] = $tipo;
        return $validated;
    }

    /**
     * Ver detalle de cliente externo
     */
    public function show(string $id): JsonResponse
    {
        $cliente = ClienteExterno::with('contactos')->findOrFail($id);
        return response()->json(['data' => $cliente]);
    }

    /**
     * Actualizar cliente externo
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $cliente = ClienteExterno::findOrFail($id);
        $validated = $this->validatePayload($request, true);
        $tipo = $validated['tipo_cliente'];
        $contactos = $validated['contactos'] ?? [];
        unset($validated['contactos']);

        DB::transaction(function () use ($cliente, $validated, $tipo, $contactos) {
            $cliente->update($this->normalizar($validated));
            if ($tipo === 'empresa') {
                $this->syncContactos($cliente, $contactos);
            } else {
                $cliente->contactos()->where('activo', true)->update(['activo' => false, 'es_principal' => false]);
            }
        });

        return response()->json(['data' => $cliente->fresh('contactos')]);
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

        $cliente = ClienteExterno::where('cedula', trim($request->cedula))->first();

        if (!$cliente) {
            return response()->json(['data' => null], 200);
        }

        return response()->json(['data' => $cliente]);
    }

    private function normalizar(array $datos): array
    {
        foreach (['nombres', 'nombre_empresa', 'apellidos', 'direccion', 'ocupacion', 'estado_civil'] as $campo) {
            if (array_key_exists($campo, $datos) && $datos[$campo] !== null) {
                $datos[$campo] = trim((string) $datos[$campo]);
            }
        }
        if (! empty($datos['correo'])) $datos['correo'] = strtolower(trim($datos['correo']));
        if (! empty($datos['cedula'])) $datos['cedula'] = preg_replace('/\D+/', '', $datos['cedula']);
        if (! empty($datos['ruc'])) $datos['ruc'] = preg_replace('/\D+/', '', $datos['ruc']);
        if (! empty($datos['celular'])) $datos['celular'] = preg_replace('/[^0-9+]/', '', $datos['celular']);
        return $datos;
    }

    private function syncContactos(ClienteExterno $cliente, array $contactos): void
    {
        $principalAsignado = false;
        $ids = [];

        $cliente->contactos()->update(['es_principal' => false]);

        foreach ($contactos as $contacto) {
            $id = $contacto['id'] ?? null;
            unset($contacto['id']);
            $contacto['cliente_externo_id'] = $cliente->id;
            $contacto['activo'] = (bool) ($contacto['activo'] ?? true);
            $requestedPrincipal = (bool) ($contacto['es_principal'] ?? false);
            $contacto['es_principal'] = $contacto['activo'] && $requestedPrincipal && !$principalAsignado;
            if ($contacto['es_principal']) $principalAsignado = true;

            $model = $id
                ? $cliente->contactos()->whereKey($id)->firstOrFail()
                : new ClienteExternoContacto();
            $model->fill($contacto);
            $model->save();
            $ids[] = $model->id;
        }

        if (!$principalAsignado) {
            $cliente->contactos()->where('activo', true)->orderBy('created_at')->limit(1)->update(['es_principal' => true]);
        }

        $cliente->contactos()->whereNotIn('id', $ids)->update(['activo' => false, 'es_principal' => false]);
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

        $tieneServicios = $radioIds->isNotEmpty() || $aulaIds->isNotEmpty() || $podcastIds->isNotEmpty() || $equipoIds->isNotEmpty() || $edicionIds->isNotEmpty();
        if (!$tieneServicios) {
            return response()->json(['data' => []]);
        }

        $cuentas = CuentaPorCobrar::with([
            'transacciones',
            'reservaRadio.tarifa',
            'reservaAula.aula',
            'reservaPodcast.paquete',
            'alquilerEquipo.equipo',
            'edicionVideo',
        ])->where(function ($q) use ($radioIds, $aulaIds, $podcastIds, $equipoIds, $edicionIds) {
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

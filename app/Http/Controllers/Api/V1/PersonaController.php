<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\CuentaSistema;
use App\Http\Requests\StorePersonaRequest;
use App\Http\Requests\UpdatePersonaRequest;
use App\Http\Requests\StoreCuentaSistemaRequest;
use App\Http\Requests\UpdateCuentaSistemaRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class PersonaController extends Controller
{
    /**
     * POST /api/v1/academic/personas/completo
     * Crea persona + perfil + cuenta en una sola transacción
     */
    public function storeCompleto(Request $request)
    {
        $validated = validator($request->all(), [
            'tipo' => 'required|in:instructor,staff,secretaria,admin',
            'cedula' => 'nullable|string|max:20|unique:personas,cedula',
            'nombres' => 'required|string|max:100',
            'apellidos' => 'required|string|max:100',
            'correo' => 'nullable|email|max:150',
            'celular' => 'nullable|string|max:20',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'es_activo' => 'boolean',
            // Perfil Instructor
            'especialidad' => 'nullable|string|max:200',
            'bio' => 'nullable|string',
            // Perfil Staff
            'cargo' => 'required_if:tipo,staff,secretaria,admin|string|max:100',
            'salario_base' => 'nullable|numeric|min:0',
            'fecha_ingreso' => 'nullable|date',
            'es_pasante' => 'boolean',
            // Cuenta
            'crear_cuenta' => 'boolean',
            'username' => 'required_if:crear_cuenta,true|string|max:100|unique:cuentas_sistema,username',
            'password' => 'required_if:crear_cuenta,true|string|min:6|max:100',
        ], [
            'cargo.required_if' => 'El cargo es obligatorio para staff/secretaria/admin',
            'username.required_if' => 'El usuario es obligatorio al crear cuenta',
            'password.required_if' => 'La contraseña es obligatoria al crear cuenta',
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $persona = Persona::create($validated);

            if ($validated['tipo'] === 'instructor') {
                \App\Models\PerfilInstructor::create([
                    'persona_id' => $persona->id,
                    'especialidad' => $validated['especialidad'] ?? null,
                    'bio' => $validated['bio'] ?? null,
                ]);
            } else {
                \App\Models\PerfilStaff::create([
                    'persona_id' => $persona->id,
                    'cargo' => $validated['cargo'],
                    'salario_base' => $validated['salario_base'] ?? null,
                    'fecha_ingreso' => $validated['fecha_ingreso'] ?? null,
                    'es_pasante' => $validated['es_pasante'] ?? false,
                ]);
            }

            if (($validated['crear_cuenta'] ?? false) && !empty($validated['username'])) {
                $cuenta = CuentaSistema::create([
                    'persona_id' => $persona->id,
                    'username' => $validated['username'],
                    'password_hash' => $validated['password'],
                ]);

                $this->asignarRol($cuenta);
            }

            $persona->load(['ciudad', 'cuentaSistema', 'perfilInstructor', 'perfilStaff']);

            return response()->json([
                'data' => $persona,
                'message' => $validated['crear_cuenta'] ?? false
                    ? 'Persona y cuenta creadas exitosamente'
                    : 'Persona creada exitosamente',
            ], Response::HTTP_CREATED);
        });
    }

    /**
     * GET /api/v1/academic/personas
     */
    public function index(Request $request)
    {
        $query = Persona::with(['ciudad', 'cuentaSistema', 'perfilInstructor', 'perfilStaff']);

        if ($request->has('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->has('activos')) {
            $query->where('es_activo', $request->activos === 'true');
        }

        if ($request->has('buscar')) {
            $query->buscar($request->buscar);
        }

        if ($request->has('ciudad_id')) {
            $query->where('ciudad_id', $request->ciudad_id);
        }

        $query->orderBy('apellidos')->orderBy('nombres');

        $perPage = $request->get('per_page', 15);
        $personas = $query->paginate($perPage);

        return response()->json([
            'data' => $personas->items(),
            'meta' => [
                'total' => $personas->total(),
                'per_page' => $personas->perPage(),
                'current_page' => $personas->currentPage(),
                'last_page' => $personas->lastPage(),
            ]
        ]);
    }

    /**
     * POST /api/v1/academic/personas
     */
    public function store(StorePersonaRequest $request)
    {
        $persona = Persona::create($request->validated());

        return response()->json([
            'data' => $persona,
            'message' => 'Persona creada exitosamente'
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/academic/personas/{id}
     */
    public function show($id)
    {
        $persona = Persona::with(['ciudad', 'cuentaSistema', 'perfilInstructor', 'perfilStaff'])->findOrFail($id);

        return response()->json(['data' => $persona]);
    }

    /**
     * PUT /api/v1/academic/personas/{id}
     */
    public function update(UpdatePersonaRequest $request, $id)
    {
        $persona = Persona::findOrFail($id);
        $persona->update($request->validated());

        if ($persona->cuentaSistema) {
            $this->asignarRol($persona->cuentaSistema);
        }

        return response()->json([
            'data' => $persona,
            'message' => 'Persona actualizada exitosamente'
        ]);
    }

    /**
     * DELETE /api/v1/academic/personas/{id}
     */
    public function destroy($id)
    {
        Persona::findOrFail($id)->delete();

        return response()->json(['message' => 'Persona eliminada exitosamente']);
    }

    /**
     * POST /api/v1/academic/personas/{id}/cuenta
     */
    public function crearCuenta(StoreCuentaSistemaRequest $request, $id)
    {
        $persona = Persona::findOrFail($id);

        $cuenta = CuentaSistema::create([
            'persona_id' => $id,
            'username' => $request->username,
            'password_hash' => $request->password,
        ]);

        $this->asignarRol($cuenta);

        return response()->json([
            'data' => $cuenta,
            'message' => 'Cuenta creada exitosamente'
        ], Response::HTTP_CREATED);
    }

    public function actualizarCuenta(UpdateCuentaSistemaRequest $request, $id)
    {
        $persona = Persona::findOrFail($id);
        $cuenta = $persona->cuentaSistema;

        if (!$cuenta) {
            return response()->json(['message' => 'La persona no tiene una cuenta de sistema'], 404);
        }

        if ($request->filled('username')) {
            $cuenta->username = $request->username;
        }

        if ($request->filled('password')) {
            $cuenta->password_hash = $request->password;
        }

        $cuenta->save();

        return response()->json([
            'data' => $cuenta->fresh(),
            'message' => 'Cuenta actualizada exitosamente'
        ]);
    }

    private function asignarRol(CuentaSistema $cuenta): void
    {
        $cuenta->loadMissing('persona');

        if (!$cuenta->persona) return;

        $mapa = [
            'admin' => 'Administrador',
            'instructor' => 'Instructor',
            'staff' => 'Staff',
            'secretaria' => 'Staff',
        ];

        $nombreRol = $mapa[$cuenta->persona->tipo] ?? null;

        if (!$nombreRol) return;

        $rol = Role::firstOrCreate(['name' => $nombreRol]);
        $cuenta->assignRole($rol);
    }
}

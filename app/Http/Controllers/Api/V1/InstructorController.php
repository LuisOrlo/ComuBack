<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\PerfilInstructor;
use App\Models\HorasInstructor;
use App\Http\Requests\StorePerfilInstructorRequest;
use Illuminate\Http\Request;

class InstructorController extends Controller
{
    /**
     * GET /api/v1/academic/instructores
     */
    public function index(Request $request)
    {
        $query = Persona::with(['perfilInstructor', 'ciudad', 'cuentaSistema'])
            ->instructores();

        if ($request->has('buscar')) {
            $query->buscar($request->buscar);
        }

        if ($request->has('ciudad_id')) {
            $query->where('ciudad_id', $request->ciudad_id);
        }

        if ($request->has('activos')) {
            $query->where('es_activo', $request->activos === 'true');
        }

        $query->orderBy('apellidos')->orderBy('nombres');

        $perPage = $request->get('per_page', 15);
        $instructores = $query->paginate($perPage);

        return response()->json([
            'data' => $instructores->items(),
            'meta' => [
                'total' => $instructores->total(),
                'per_page' => $instructores->perPage(),
                'current_page' => $instructores->currentPage(),
                'last_page' => $instructores->lastPage(),
            ]
        ]);
    }

    /**
     * GET /api/v1/academic/instructores/disponibles
     */
    public function disponibles()
    {
        $instructores = Persona::instructores()->activos()
            ->with('perfilInstructor:id,persona_id,especialidad')
            ->select('id', 'nombres', 'apellidos')
            ->orderBy('apellidos')->orderBy('nombres')
            ->get();

        return response()->json(['data' => $instructores]);
    }

    /**
     * GET /api/v1/academic/instructores/{id}
     */
    public function show($id)
    {
        $persona = Persona::with(['perfilInstructor', 'ciudad', 'cuentaSistema'])
            ->findOrFail($id);

        return response()->json(['data' => $persona]);
    }

    /**
     * POST /api/v1/academic/instructores/{id}/perfil
     */
    public function updatePerfil(StorePerfilInstructorRequest $request, $id)
    {
        $persona = Persona::findOrFail($id);

        $perfil = PerfilInstructor::updateOrCreate(
            ['persona_id' => $id],
            $request->only(['especialidad', 'bio'])
        );

        return response()->json([
            'data' => $perfil,
            'message' => 'Perfil actualizado exitosamente'
        ]);
    }

    /**
     * GET /api/v1/academic/instructores/{id}/cursos
     */
    public function cursos($id)
    {
        $persona = Persona::findOrFail($id);

        $cursos = \App\Models\CursoAbierto::with('catalogo:id,nombre')
            ->where('docente_id', $id)
            ->orderBy('fecha_inicio', 'desc')
            ->paginate(15);

        return response()->json([
            'data' => $cursos->items(),
            'meta' => [
                'total' => $cursos->total(),
                'per_page' => $cursos->perPage(),
            ]
        ]);
    }

    /**
     * GET /api/v1/academic/instructores/{id}/horas
     */
    public function horas($id)
    {
        $horas = HorasInstructor::where('instructor_id', $id)
            ->orderBy('fecha', 'desc')
            ->paginate(15);

        return response()->json([
            'data' => $horas->items(),
            'meta' => [
                'total' => $horas->total(),
                'per_page' => $horas->perPage(),
            ]
        ]);
    }
}

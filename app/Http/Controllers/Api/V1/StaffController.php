<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\PerfilStaff;
use App\Models\RegistroAsistenciaStaff;
use App\Http\Requests\StorePerfilStaffRequest;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    /**
     * GET /api/v1/academic/staff
     */
    public function index(Request $request)
    {
        $query = Persona::with(['perfilStaff', 'ciudad', 'cuentaSistema'])
            ->staff();

        if ($request->has('buscar')) {
            $query->buscar($request->buscar);
        }

        if ($request->has('cargo')) {
            $query->whereHas('perfilStaff', function ($q) use ($request) {
                $q->where('cargo', 'ilike', '%' . $request->cargo . '%');
            });
        }

        if ($request->has('es_pasante')) {
            $query->whereHas('perfilStaff', function ($q) use ($request) {
                $q->where('es_pasante', $request->es_pasante === 'true');
            });
        }

        $query->orderBy('apellidos')->orderBy('nombres');

        $perPage = $request->get('per_page', 15);
        $staffList = $query->paginate($perPage);

        return response()->json([
            'data' => $staffList->items(),
            'meta' => [
                'total' => $staffList->total(),
                'per_page' => $staffList->perPage(),
                'current_page' => $staffList->currentPage(),
                'last_page' => $staffList->lastPage(),
            ]
        ]);
    }

    /**
     * GET /api/v1/academic/staff/{id}
     */
    public function show($id)
    {
        $persona = Persona::with(['perfilStaff', 'ciudad', 'cuentaSistema'])
            ->findOrFail($id);

        return response()->json(['data' => $persona]);
    }

    /**
     * POST /api/v1/academic/staff/{id}/perfil
     */
    public function updatePerfil(StorePerfilStaffRequest $request, $id)
    {
        $persona = Persona::findOrFail($id);

        $perfil = PerfilStaff::updateOrCreate(
            ['persona_id' => $id],
            $request->only(['cargo', 'salario_base', 'fecha_ingreso', 'es_pasante'])
        );

        return response()->json([
            'data' => $perfil,
            'message' => 'Perfil actualizado exitosamente'
        ]);
    }

    /**
     * GET /api/v1/academic/staff/{id}/asistencia
     */
    public function asistencia($id)
    {
        $asistencias = RegistroAsistenciaStaff::where('persona_id', $id)
            ->orderBy('fecha', 'desc')
            ->paginate(15);

        return response()->json([
            'data' => $asistencias->items(),
            'meta' => [
                'total' => $asistencias->total(),
                'per_page' => $asistencias->perPage(),
            ]
        ]);
    }
}

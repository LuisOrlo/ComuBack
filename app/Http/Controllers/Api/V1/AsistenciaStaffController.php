<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RegistroAsistenciaStaff;
use App\Models\Persona;
use App\Http\Requests\StoreAsistenciaStaffRequest;
use App\Http\Requests\UpdateAsistenciaStaffRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AsistenciaStaffController extends Controller
{
    /**
     * GET /api/v1/academic/asistencia-staff
     */
    public function index(Request $request)
    {
        $query = RegistroAsistenciaStaff::with('persona:id,nombres,apellidos');

        if ($request->has('persona_id')) {
            $query->where('persona_id', $request->persona_id);
        }

        if ($request->has('fecha')) {
            $query->where('fecha', $request->fecha);
        }

        if ($request->has('desde')) {
            $query->where('fecha', '>=', $request->desde);
        }

        if ($request->has('hasta')) {
            $query->where('fecha', '<=', $request->hasta);
        }

        $query->orderBy('fecha', 'desc')->orderBy('hora_entrada', 'desc');

        $perPage = $request->get('per_page', 30);
        $asistencias = $query->paginate($perPage);

        return response()->json([
            'data' => $asistencias->items(),
            'meta' => [
                'total' => $asistencias->total(),
                'per_page' => $asistencias->perPage(),
                'current_page' => $asistencias->currentPage(),
                'last_page' => $asistencias->lastPage(),
            ]
        ]);
    }

    /**
     * POST /api/v1/academic/asistencia-staff
     */
    public function store(StoreAsistenciaStaffRequest $request)
    {
        $data = $request->validated();
        $data['registrado_por'] = auth()->id();

        $asistencia = RegistroAsistenciaStaff::create($data);

        return response()->json([
            'data' => $asistencia,
            'message' => 'Asistencia registrada exitosamente'
        ], Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/academic/asistencia-staff/{id}
     */
    public function update(UpdateAsistenciaStaffRequest $request, $id)
    {
        $asistencia = RegistroAsistenciaStaff::findOrFail($id);
        $asistencia->update($request->validated());

        return response()->json([
            'data' => $asistencia,
            'message' => 'Asistencia actualizada exitosamente'
        ]);
    }

    /**
     * DELETE /api/v1/academic/asistencia-staff/{id}
     */
    public function destroy($id)
    {
        RegistroAsistenciaStaff::findOrFail($id)->delete();

        return response()->json(['message' => 'Registro eliminado exitosamente']);
    }
}

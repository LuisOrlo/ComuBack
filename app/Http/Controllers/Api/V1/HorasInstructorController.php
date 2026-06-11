<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HorasInstructor;
use App\Http\Requests\StoreHorasInstructorRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HorasInstructorController extends Controller
{
    /**
     * GET /api/v1/academic/horas-instructor
     */
    public function index(Request $request)
    {
        $query = HorasInstructor::with('instructor:id,nombres,apellidos');

        if ($request->has('instructor_id')) {
            $query->where('instructor_id', $request->instructor_id);
        }

        if ($request->has('pagado')) {
            $query->where('pagado', $request->pagado === 'true');
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

        $query->orderBy('fecha', 'desc');

        $perPage = $request->get('per_page', 15);
        $horas = $query->paginate($perPage);

        return response()->json([
            'data' => $horas->items(),
            'meta' => [
                'total' => $horas->total(),
                'per_page' => $horas->perPage(),
                'current_page' => $horas->currentPage(),
                'last_page' => $horas->lastPage(),
            ]
        ]);
    }

    /**
     * POST /api/v1/academic/horas-instructor
     */
    public function store(StoreHorasInstructorRequest $request)
    {
        $hora = HorasInstructor::create($request->validated());

        return response()->json([
            'data' => $hora,
            'message' => 'Horas registradas exitosamente'
        ], Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/academic/horas-instructor/{id}
     */
    public function update(Request $request, $id)
    {
        $hora = HorasInstructor::findOrFail($id);
        $hora->update($request->only(['horas_trabajadas', 'tarifa_aplicada', 'pagado']));

        return response()->json([
            'data' => $hora,
            'message' => 'Actualizado exitosamente'
        ]);
    }

    /**
     * DELETE /api/v1/academic/horas-instructor/{id}
     */
    public function destroy($id)
    {
        HorasInstructor::findOrFail($id)->delete();

        return response()->json(['message' => 'Registro eliminado exitosamente']);
    }

    /**
     * POST /api/v1/academic/horas-instructor/bulk/pagar
     */
    public function bulkPagar(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|max:100',
            'ids.*' => 'uuid|exists:horas_instructor,id',
        ]);

        HorasInstructor::whereIn('id', $request->ids)->update(['pagado' => true]);

        return response()->json([
            'message' => count($request->ids) . ' registros marcados como pagados'
        ]);
    }
}

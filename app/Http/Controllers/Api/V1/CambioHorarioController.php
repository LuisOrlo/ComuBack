<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CambioHorario;
use App\Http\Requests\StoreCambioHorarioRequest;
use App\Http\Requests\UpdateCambioHorarioRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CambioHorarioController extends Controller
{
    public function index(Request $request)
    {
        $query = CambioHorario::query();

        if ($request->has('matricula_origen_id')) {
            $query->where('matricula_origen_id', $request->matricula_origen_id);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('pendientes') && $request->pendientes == 'true') {
            $query->pendientes();
        }

        $perPage = $request->get('per_page', 15);
        $cambios = $query->with(['matriculaOrigen', 'cursoAbiertoAntiguo', 'cursoAbiertoNuevo'])->paginate($perPage);

        return response()->json([
            'data' => $cambios->items(),
            'meta' => [
                'total' => $cambios->total(),
                'per_page' => $cambios->perPage(),
                'current_page' => $cambios->currentPage(),
                'last_page' => $cambios->lastPage(),
            ]
        ]);
    }

    public function store(StoreCambioHorarioRequest $request)
    {
        $cambio = CambioHorario::create($request->validated());
        return response()->json(['data' => $cambio, 'message' => 'Solicitud creada exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $cambio = CambioHorario::with(['matriculaOrigen', 'cursoAbiertoAntiguo', 'cursoAbiertoNuevo'])->findOrFail($id);
        return response()->json(['data' => $cambio]);
    }

    public function update(UpdateCambioHorarioRequest $request, $id)
    {
        $cambio = CambioHorario::findOrFail($id);
        $cambio->update($request->validated());
        return response()->json(['data' => $cambio, 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        CambioHorario::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function aprobar($id)
    {
        $cambio = CambioHorario::findOrFail($id);

        if (!$cambio->puedeSerAprobada()) {
            return response()->json(['message' => 'Este cambio no puede ser aprobado'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cambio->update(['estado' => 'aprobado']);
        return response()->json(['data' => $cambio, 'message' => 'Cambio aprobado exitosamente']);
    }

    public function rechazar($id)
    {
        $cambio = CambioHorario::findOrFail($id);

        if (!$cambio->puedeSerRechazada()) {
            return response()->json(['message' => 'Este cambio no puede ser rechazado'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cambio->update(['estado' => 'rechazado']);
        return response()->json(['data' => $cambio, 'message' => 'Cambio rechazado exitosamente']);
    }

    public function completar($id)
    {
        $cambio = CambioHorario::findOrFail($id);

        if (!$cambio->puedeSerCompletada()) {
            return response()->json(['message' => 'Este cambio no puede ser completado'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cambio->update(['estado' => 'completado']);
        return response()->json(['data' => $cambio, 'message' => 'Cambio completado exitosamente']);
    }
}

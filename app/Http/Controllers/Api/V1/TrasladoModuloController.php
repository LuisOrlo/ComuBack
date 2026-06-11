<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TrasladoModulo;
use App\Http\Requests\StoreTrasladoModuloRequest;
use App\Http\Requests\UpdateTrasladoModuloRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrasladoModuloController extends Controller
{
    public function index(Request $request)
    {
        $query = TrasladoModulo::query();

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
        $traslados = $query->with(['matriculaOrigen', 'moduloAntiguo', 'moduloNuevo'])->paginate($perPage);

        return response()->json([
            'data' => $traslados->items(),
            'meta' => [
                'total' => $traslados->total(),
                'per_page' => $traslados->perPage(),
                'current_page' => $traslados->currentPage(),
                'last_page' => $traslados->lastPage(),
            ]
        ]);
    }

    public function store(StoreTrasladoModuloRequest $request)
    {
        $traslado = TrasladoModulo::create($request->validated());
        return response()->json(['data' => $traslado, 'message' => 'Solicitud creada exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $traslado = TrasladoModulo::with(['matriculaOrigen', 'moduloAntiguo', 'moduloNuevo'])->findOrFail($id);
        return response()->json(['data' => $traslado]);
    }

    public function update(UpdateTrasladoModuloRequest $request, $id)
    {
        $traslado = TrasladoModulo::findOrFail($id);
        $traslado->update($request->validated());
        return response()->json(['data' => $traslado, 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        TrasladoModulo::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function aprobar($id)
    {
        $traslado = TrasladoModulo::findOrFail($id);

        if (!$traslado->puedeSerAprobado()) {
            return response()->json(['message' => 'Este traslado no puede ser aprobado'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $traslado->update(['estado' => 'aprobado']);
        return response()->json(['data' => $traslado, 'message' => 'Traslado aprobado exitosamente']);
    }

    public function rechazar($id)
    {
        $traslado = TrasladoModulo::findOrFail($id);

        if (!$traslado->puedeSerRechazado()) {
            return response()->json(['message' => 'Este traslado no puede ser rechazado'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $traslado->update(['estado' => 'rechazado']);
        return response()->json(['data' => $traslado, 'message' => 'Traslado rechazado exitosamente']);
    }

    public function completar($id)
    {
        $traslado = TrasladoModulo::findOrFail($id);

        if (!$traslado->puedeSerCompletado()) {
            return response()->json(['message' => 'Este traslado no puede ser completado'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $traslado->update(['estado' => 'completado']);
        return response()->json(['data' => $traslado, 'message' => 'Traslado completado exitosamente']);
    }
}

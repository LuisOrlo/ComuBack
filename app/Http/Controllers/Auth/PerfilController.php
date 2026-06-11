<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PerfilRequest;
use App\Http\Resources\Auth\CuentaSistemaResource;
use Illuminate\Http\JsonResponse;

class PerfilController extends Controller
{
    public function mostrar(): JsonResponse
    {
        $cuenta = auth()->user()->load('persona');

        return response()->json([
            'datos' => new CuentaSistemaResource($cuenta),
        ]);
    }

    public function actualizar(PerfilRequest $request): JsonResponse
    {
        $cuenta = auth()->user()->load('persona');
        $datos = $request->validated();

        $cuenta->persona->update($datos);

        $cuenta->refresh();
        $cuenta->load('persona');

        return response()->json([
            'mensaje' => 'Perfil actualizado exitosamente.',
            'datos' => new CuentaSistemaResource($cuenta),
        ]);
    }
}

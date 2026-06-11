<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\CuentaSistemaResource;
use App\Models\CuentaSistema;
use App\Traits\Auditable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use Auditable;

    public function login(LoginRequest $request): JsonResponse
    {
        $credenciales = $request->validated();

        if (!Auth::attempt(['username' => $credenciales['username'], 'password' => $credenciales['password']])) {
            $this->audit('login_fallido', [
                'username' => $credenciales['username'],
            ]);
            return response()->json([
                'success' => false,
                'mensaje' => 'Las credenciales proporcionadas son incorrectas.',
            ]);
        }

        $cuenta = CuentaSistema::query()
            ->where('username', $credenciales['username'])
            ->firstOrFail();

        $cuenta->update(['last_login' => now()]);

        $token = $cuenta->createToken('token-acceso');

        $this->audit('login_exitoso', [
            'username' => $credenciales['username'],
        ]);

        return response()->json([
            'mensaje' => 'Inicio de sesión exitoso.',
            'datos' => [
                'token' => $token->plainTextToken,
                'usuario' => new CuentaSistemaResource($cuenta),
            ],
        ]);
    }

    public function logout(): JsonResponse
    {
        $cuenta = auth()->user();

        if ($cuenta) {
            $cuenta->currentAccessToken()->delete();
        }

        return response()->json([
            'mensaje' => 'Sesión cerrada exitosamente.',
        ]);
    }
}

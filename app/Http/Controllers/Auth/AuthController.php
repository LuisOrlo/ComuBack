<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\CuentaSistemaResource;
use App\Models\CuentaSistema;
use App\Traits\Auditable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use Auditable;

    public function login(LoginRequest $request): JsonResponse
    {
        $credenciales = $request->validated();
        $ip = $request->ip();
        $throttleKey = Str::lower($credenciales['username']) . '|' . $ip;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success' => false,
                'mensaje' => "Demasiados intentos de inicio de sesión. Por favor intente nuevamente en {$seconds} segundos.",
            ], 429);
        }

        if (!Auth::attempt(['username' => $credenciales['username'], 'password' => $credenciales['password']])) {
            RateLimiter::hit($throttleKey, 300);
            $this->audit('login_fallido', [
                'username' => $credenciales['username'],
                'ip' => $ip,
            ]);
            return response()->json([
                'success' => false,
                'mensaje' => 'Las credenciales proporcionadas son incorrectas.',
            ], 401);
        }

        $cuenta = CuentaSistema::query()
            ->where('username', $credenciales['username'])
            ->with('persona')
            ->firstOrFail();

        // Validar que la persona vinculada esté activa y no eliminada
        if (!$cuenta->persona || !$cuenta->persona->es_activo || $cuenta->persona->trashed()) {
            Auth::logout();
            RateLimiter::hit($throttleKey, 300);
            $this->audit('login_cuenta_inactiva', [
                'username' => $credenciales['username'],
                'persona_id' => $cuenta->persona_id,
            ]);
            return response()->json([
                'success' => false,
                'mensaje' => 'La cuenta de usuario se encuentra inactiva o deshabilitada.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $cuenta->update(['last_login' => now()]);

        $token = $cuenta->createToken(
            'token-acceso',
            ['*'],
            now()->addMinutes((int) config('sanctum.expiration', 480))
        );

        $this->audit('login_exitoso', [
            'username' => $credenciales['username'],
            'ip' => $ip,
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

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $user->loadMissing('persona');
            if (!$user->persona || !$user->persona->es_activo || $user->persona->trashed()) {
                if (method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
                    $user->currentAccessToken()->delete();
                }

                return response()->json([
                    'success' => false,
                    'mensaje' => 'La cuenta de usuario se encuentra inactiva o deshabilitada.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }
}

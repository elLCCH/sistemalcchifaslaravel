<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class CheckAbilities
{
    public function handle(Request $request, Closure $next, ...$abilities)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Token no proporcionado'], 401);
        }

        $personalAccessToken = PersonalAccessToken::findToken($token);
        if (!$personalAccessToken) {
            return response()->json(['message' => 'Token inválido o expirado'], 401);
        }

        $cliente = $personalAccessToken->tokenable;
        if (!$cliente) {
            return response()->json(['message' => 'Usuario no encontrado'], 401);
        }

        $hasAbility = false;
        foreach ($abilities as $ability) {
            if ($personalAccessToken->can($ability)) {
                $hasAbility = true;
                break;
            }
        }

        if (!$hasAbility) {
            return response()->json(['message' => 'PERMISOS INSUFICIENTES.'], 403);
        }

        return $next($request);
    }

}

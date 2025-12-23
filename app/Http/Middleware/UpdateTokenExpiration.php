<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;

class UpdateTokenExpiration
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $token = $request->bearerToken();

        if (!$token) {
            return $next($request);
        }

        $personalAccessToken = PersonalAccessToken::findToken($token);

        if (!$personalAccessToken) {
            return $next($request);
        }

        $now = Carbon::now();

        // Si tiene expiración y ya venció
        if ($personalAccessToken->expires_at &&
            $now->greaterThan($personalAccessToken->expires_at)) {
            return response()->json([
                'message' => 'EL TOKEN ESTA EXPIRADO'
            ], 401);
        }

        // Renovar expiración (sliding expiration)
        $personalAccessToken->expires_at = $now->copy()->addMinutes(60);
        $personalAccessToken->save();

        return $next($request);
    }
}

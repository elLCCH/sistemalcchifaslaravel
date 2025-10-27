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
        if ($request->user()) {
            $token = $request->bearerToken();
            if ($token) {
                $personalAccessToken = PersonalAccessToken::findToken($token);
                if ($personalAccessToken) {
                    $expiresAt = Carbon::parse($personalAccessToken->expires_at);
                    $now = Carbon::now();

                    if ($now->gt($expiresAt)) {
                        return response()->json(['message' => 'EL TOKEN ESTA EXPIRADO'], 401);
                    }

                    $personalAccessToken->expires_at = $now->addMinutes(60);
                    $personalAccessToken->save();
                }
            }
        }

        return $next($request);
    }
}

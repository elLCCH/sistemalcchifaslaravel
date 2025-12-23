<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;

class TokenController extends Controller
{
    public function verify(Request $request)
    {
        $token = $request->input('token');
        $tokenParts = explode('|', $token);
        $tokenId = $tokenParts[0] ?? null;
        $tokenValue = $tokenParts[1] ?? null;

        if ($tokenId && $tokenValue) {
            $personalAccessToken = PersonalAccessToken::find($tokenId);
            if ($personalAccessToken && hash_equals($personalAccessToken->token, hash('sha256', $tokenValue))) {
                // Verificar si el token ha expirado
                if ($personalAccessToken->expires_at && Carbon::now()->greaterThan(Carbon::parse($personalAccessToken->expires_at))) {
                    return response()->json(['message' => 'Token expired'], 401);
                }

                $user = $personalAccessToken->tokenable;
                return response()->json(['name' => $personalAccessToken->name, 'user' => $user], 200);
            }
        }

        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\AsistenciaQrToken;
use App\Models\AsistenciaSesion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AsistenciaQrTokenController extends Controller
{
    public function create(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        if ($sesion->estado !== 'ABIERTA') {
            return response()->json(['ok' => false, 'message' => 'La sesión no está abierta.'], 409);
        }

        $validator = Validator::make($request->all(), [
            'ttl_seconds' => 'nullable|integer|min:2|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $ttl = (int) ($validator->validated()['ttl_seconds'] ?? 5);

        // Si pasaron 30 minutos desde hora_ingreso, no generar más tokens.
        $limite = $sesion->hora_ingreso->copy()->addMinutes($sesion->minutos_falta ?? 30);
        if (now()->greaterThanOrEqualTo($limite)) {
            return response()->json([
                'ok' => false,
                'message' => 'La sesión ya venció (pasaron 30 minutos).',
            ], 409);
        }

        $token = Str::random(64);
        $expiresAt = now()->addSeconds($ttl);

        $row = AsistenciaQrToken::create([
            'asistencias_sesiones_id' => $sesion->id,
            'token' => $token,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'qr' => [
                'id' => $row->id,
                'token' => $row->token,
                'expires_at' => $row->expires_at,
            ],
        ]);
    }
}

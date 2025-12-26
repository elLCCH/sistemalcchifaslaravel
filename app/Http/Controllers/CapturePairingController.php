<?php

namespace App\Http\Controllers;

use App\Models\CapturePairing;
use App\Models\CaptureSession;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CapturePairingController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        if (!$institucionId) {
            return response()->json(['error' => 'Usuario sin institución'], 422);
        }

        $token = Str::random(64);
        $expiresAt = now()->addDays(30);

        $pairing = CapturePairing::create([
            'token' => $token,
            'institucion_id' => $institucionId,
            'user_id' => $user?->id,
            'status' => 'PENDING',
            'device_label' => null,
            'pending_capture_token' => null,
            'linked_at' => null,
            'last_seen_at' => null,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'data' => [
                'token' => $pairing->token,
                'status' => $pairing->status,
                'expires_at' => $pairing->expires_at,
            ]
        ]);
    }

    public function show(string $token)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $pairing = CapturePairing::where('token', '=', $token)->firstOrFail();

        if ($institucionId && $pairing->institucion_id !== $institucionId) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        if (!in_array($pairing->status, ['REVOKED', 'EXPIRED'], true) && $pairing->expires_at && now()->greaterThan($pairing->expires_at)) {
            $pairing->status = 'EXPIRED';
            $pairing->save();
        }

        return response()->json([
            'data' => [
                'token' => $pairing->token,
                'status' => $pairing->status,
                'device_label' => $pairing->device_label,
                'linked_at' => $pairing->linked_at,
                'last_seen_at' => $pairing->last_seen_at,
                'expires_at' => $pairing->expires_at,
                'pending_capture_token' => $pairing->pending_capture_token,
            ]
        ]);
    }

    // Público: el celular confirma la vinculación (por token).
    public function link(string $token, Request $request)
    {
        $pairing = CapturePairing::where('token', '=', $token)->first();
        if (!$pairing) {
            return response()->json(['error' => 'Vinculación no encontrada'], 404);
        }

        if ($pairing->status === 'REVOKED') {
            return response()->json(['error' => 'Vinculación revocada'], 409);
        }

        if ($pairing->expires_at && now()->greaterThan($pairing->expires_at)) {
            $pairing->status = 'EXPIRED';
            $pairing->save();
            return response()->json(['error' => 'Vinculación expirada'], 410);
        }

        $validated = $request->validate([
            'device_label' => ['nullable', 'string', 'max:100'],
        ]);

        if ($pairing->status !== 'LINKED') {
            $pairing->status = 'LINKED';
            $pairing->linked_at = $pairing->linked_at ?: now();
        }

        if (!empty($validated['device_label'])) {
            $pairing->device_label = $validated['device_label'];
        }

        $pairing->last_seen_at = now();
        $pairing->save();

        return response()->json([
            'data' => [
                'status' => $pairing->status,
                'device_label' => $pairing->device_label,
                'linked_at' => $pairing->linked_at,
                'last_seen_at' => $pairing->last_seen_at,
            ]
        ]);
    }

    // Público: el celular pregunta si hay una captura pendiente.
    public function pendingCapture(string $token)
    {
        $pairing = CapturePairing::where('token', '=', $token)->first();
        if (!$pairing) {
            return response()->json(['error' => 'Vinculación no encontrada'], 404);
        }

        if ($pairing->status !== 'LINKED') {
            return response()->json([
                'data' => [
                    'status' => $pairing->status,
                    'capture_token' => null,
                ]
            ]);
        }

        if ($pairing->expires_at && now()->greaterThan($pairing->expires_at)) {
            $pairing->status = 'EXPIRED';
            $pairing->pending_capture_token = null;
            $pairing->save();
            return response()->json(['error' => 'Vinculación expirada'], 410);
        }

        $pairing->last_seen_at = now();
        $pairing->save();

        $captureToken = $pairing->pending_capture_token;
        if (!$captureToken) {
            return response()->json([
                'data' => [
                    'status' => $pairing->status,
                    'capture_token' => null,
                ]
            ]);
        }

        $session = CaptureSession::where('token', '=', $captureToken)->first();
        if (!$session) {
            $pairing->pending_capture_token = null;
            $pairing->save();
            return response()->json([
                'data' => [
                    'status' => $pairing->status,
                    'capture_token' => null,
                ]
            ]);
        }

        if ($session->status !== 'PENDING' || now()->greaterThan($session->expires_at)) {
            if ($session->status === 'PENDING' && now()->greaterThan($session->expires_at)) {
                $session->status = 'EXPIRED';
                $session->save();
            }
            $pairing->pending_capture_token = null;
            $pairing->save();
            return response()->json([
                'data' => [
                    'status' => $pairing->status,
                    'capture_token' => null,
                ]
            ]);
        }

        return response()->json([
            'data' => [
                'status' => $pairing->status,
                'capture_token' => $session->token,
                'expires_at' => $session->expires_at,
            ]
        ]);
    }

    // Privado: PC solicita una captura (modo espera) para un estudiante.
    public function requestCapture(string $token, Request $request)
    {
        $user = $request->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        if (!$institucionId) {
            return response()->json(['error' => 'Usuario sin institución'], 422);
        }

        $pairing = CapturePairing::where('token', '=', $token)->firstOrFail();

        if ($pairing->institucion_id !== $institucionId) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        if ($pairing->status !== 'LINKED') {
            return response()->json(['error' => 'Celular no vinculado'], 409);
        }

        if ($pairing->expires_at && now()->greaterThan($pairing->expires_at)) {
            $pairing->status = 'EXPIRED';
            $pairing->pending_capture_token = null;
            $pairing->save();
            return response()->json(['error' => 'Vinculación expirada'], 410);
        }

        $validated = $request->validate([
            'estudianteifas_id' => ['nullable', 'integer'],
        ]);

        // Si ya existe una captura pendiente válida, devolverla
        if ($pairing->pending_capture_token) {
            $existing = CaptureSession::where('token', '=', $pairing->pending_capture_token)->first();
            if ($existing && $existing->status === 'PENDING' && now()->lessThanOrEqualTo($existing->expires_at)) {
                return response()->json([
                    'data' => [
                        'token' => $existing->token,
                        'status' => $existing->status,
                        'expires_at' => $existing->expires_at,
                    ]
                ], 200);
            }
            $pairing->pending_capture_token = null;
            $pairing->save();
        }

        $captureToken = Str::random(64);
        $expiresAt = now()->addMinutes(10);

        $session = CaptureSession::create([
            'token' => $captureToken,
            'institucion_id' => $institucionId,
            'user_id' => $user?->id,
            'estudianteifas_id' => $validated['estudianteifas_id'] ?? null,
            'status' => 'PENDING',
            'file_path' => null,
            'expires_at' => $expiresAt,
        ]);

        $pairing->pending_capture_token = $session->token;
        $pairing->save();

        return response()->json([
            'data' => [
                'token' => $session->token,
                'status' => $session->status,
                'expires_at' => $session->expires_at,
            ]
        ]);
    }

    // Privado: PC cancela la captura pendiente (modo espera).
    public function cancelCapture(string $token)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $pairing = CapturePairing::where('token', '=', $token)->firstOrFail();

        if ($institucionId && $pairing->institucion_id !== $institucionId) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $pending = $pairing->pending_capture_token;
        $pairing->pending_capture_token = null;
        $pairing->save();

        if (!$pending) {
            return response()->json(['data' => ['status' => 'OK']]);
        }

        $session = CaptureSession::where('token', '=', $pending)->first();
        if (!$session) {
            return response()->json(['data' => ['status' => 'OK']]);
        }

        if (!in_array($session->status, ['CANCELLED', 'EXPIRED'], true)) {
            if ($session->file_path && is_string($session->file_path)) {
                $filePath = str_replace('\\', '/', $session->file_path);
                if (str_starts_with($filePath, 'archivos/') && File::exists(public_path($filePath))) {
                    File::delete(public_path($filePath));
                }
            }

            $session->status = 'CANCELLED';
            $session->file_path = null;
            $session->save();
        }

        return response()->json(['data' => ['status' => 'OK']]);
    }
}

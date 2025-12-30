<?php

namespace App\Http\Controllers;

use App\Models\CaptureSession;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CaptureSessionController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        if (!$institucionId) {
            return response()->json(['error' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'estudianteifas_id' => ['nullable', 'integer'],
        ]);

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(10);

        $session = CaptureSession::create([
            'token' => $token,
            'institucion_id' => $institucionId,
            'user_id' => $user?->id,
            'estudianteifas_id' => $validated['estudianteifas_id'] ?? null,
            'status' => 'PENDING',
            'file_path' => null,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'data' => [
                'token' => $session->token,
                'status' => $session->status,
                'expires_at' => $session->expires_at,
            ]
        ]);
    }

    public function show(string $token)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $session = CaptureSession::where('token', '=', $token)->firstOrFail();

        if ($institucionId && $session->institucion_id !== $institucionId) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Si expiró y sigue pendiente, marcarla como EXPIRED
        if (str_starts_with($session->status, 'PENDING') && now()->greaterThan($session->expires_at)) {
            $session->status = 'EXPIRED';
            $session->save();
        }

        return response()->json([
            'data' => [
                'token' => $session->token,
                'status' => $session->status,
                'file_path' => $session->file_path,
                'expires_at' => $session->expires_at,
            ]
        ]);
    }

    public function cancel(string $token)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $session = CaptureSession::where('token', '=', $token)->firstOrFail();

        if ($institucionId && $session->institucion_id !== $institucionId) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        if (in_array($session->status, ['CANCELLED', 'EXPIRED'], true)) {
            return response()->json(['data' => ['status' => $session->status]]);
        }

        // Si ya se subió la foto, NO la borres: la PC todavía puede necesitarla para guardar.
        if ($session->status === 'UPLOADED') {
            return response()->json([
                'data' => [
                    'status' => $session->status,
                    'file_path' => $session->file_path,
                ]
            ], 200);
        }

        // Solo permitir cancelar/borrar si sigue pendiente
        if (!str_starts_with($session->status, 'PENDING')) {
            return response()->json(['data' => ['status' => $session->status]]);
        }

        // Si ya se subió algo, borrarlo para no dejar basura
        if ($session->file_path && is_string($session->file_path)) {
            $filePath = str_replace('\\', '/', $session->file_path);
            if (str_starts_with($filePath, 'archivos/') && File::exists(public_path($filePath))) {
                File::delete(public_path($filePath));
            }
        }

        $session->status = 'CANCELLED';
        $session->file_path = null;
        $session->save();

        return response()->json(['data' => ['status' => $session->status]]);
    }

    // Endpoint público (usa token) para que el celular suba la foto sin login.
    public function upload(string $token, Request $request)
    {
        $session = CaptureSession::where('token', '=', $token)->first();
        if (!$session) {
            return response()->json(['error' => 'Sesión no encontrada'], 404);
        }

        if (!str_starts_with($session->status, 'PENDING')) {
            return response()->json(['error' => 'Sesión no disponible'], 409);
        }

        if (now()->greaterThan($session->expires_at)) {
            $session->status = 'EXPIRED';
            $session->save();
            return response()->json(['error' => 'Sesión expirada'], 410);
        }

        $file = $request->file('file');
        if (!$file) {
            return response()->json(['error' => 'No se envió ningún archivo'], 400);
        }

        // Validación básica de imagen
        $mime = $file->getMimeType();
        if (!$mime || !str_starts_with($mime, 'image/')) {
            return response()->json(['error' => 'El archivo debe ser una imagen'], 422);
        }

        $base = 'archivos/institucion' . $session->institucion_id;
        $path = $session->status === 'PENDING_PAGOS'
            ? ($base . '/pagoslcch/pagosunicosgestiones')
            : ($base . '/FotosPerfiles');

        if (!File::exists(public_path($path))) {
            File::makeDirectory(public_path($path), 0755, true, true);
        }

        $safeName = time() . '_' . Str::random(8) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
        $file->move(public_path($path), $safeName);

        $session->file_path = "$path/$safeName";
        $session->status = 'UPLOADED';
        $session->save();

        return response()->json([
            'data' => [
                'status' => $session->status,
                'file_path' => $session->file_path,
            ]
        ], 200);
    }
}

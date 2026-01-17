<?php

namespace App\Http\Controllers;

use App\Models\CaptureSession;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CaptureSessionController extends Controller
{
    private const MAX_UPLOAD_BYTES = 40 * 1024 * 1024; // 40MB

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

        $iniToBytes = static function (?string $val): int {
            $val = trim((string) $val);
            if ($val === '') return 0;

            $last = strtolower(substr($val, -1));
            $num = (float) $val;
            switch ($last) {
                case 'g':
                    $num *= 1024;
                    // no break
                case 'm':
                    $num *= 1024;
                    // no break
                case 'k':
                    $num *= 1024;
                    break;
            }
            return (int) round($num);
        };

        $postMaxRaw = ini_get('post_max_size');
        $uploadMaxRaw = ini_get('upload_max_filesize');
        $postMaxBytes = $iniToBytes($postMaxRaw);
        $uploadMaxBytes = $iniToBytes($uploadMaxRaw);
        $serverLimitBytes = 0;
        foreach ([$postMaxBytes, $uploadMaxBytes] as $limit) {
            if ($limit > 0) {
                $serverLimitBytes = $serverLimitBytes > 0 ? min($serverLimitBytes, $limit) : $limit;
            }
        }
        $effectiveMaxBytes = $serverLimitBytes > 0 ? min(self::MAX_UPLOAD_BYTES, $serverLimitBytes) : self::MAX_UPLOAD_BYTES;

        $file = $request->file('file');
        if (!$file) {
            // Si excede post_max_size/upload_max_filesize, PHP no llena $_FILES y Laravel lo ve como "sin archivo".
            $contentLength = (int) ($request->server('CONTENT_LENGTH') ?? 0);
            if ($contentLength > 0 && $serverLimitBytes > 0 && $contentLength > $serverLimitBytes) {
                $maxMb = (int) floor($effectiveMaxBytes / (1024 * 1024));
                return response()->json([
                    'error' => "La foto excede el límite permitido (máx {$maxMb}MB). En el hosting ajusta post_max_size={$postMaxRaw} y upload_max_filesize={$uploadMaxRaw} a >= 40M.",
                ], 413);
            }

            return response()->json(['error' => 'No se envió ningún archivo'], 400);
        }

        if (method_exists($file, 'isValid') && !$file->isValid()) {
            $errCode = method_exists($file, 'getError') ? $file->getError() : null;
            return response()->json([
                'error' => 'Error al recibir el archivo en el servidor. Intenta nuevamente o reduce el tamaño de la foto.',
                'upload_error' => $errCode,
            ], 422);
        }

        $size = $file->getSize();
        if (is_int($size) && $size > $effectiveMaxBytes) {
            $maxMb = (int) floor($effectiveMaxBytes / (1024 * 1024));
            return response()->json([
                'error' => "La foto excede el límite permitido (máx {$maxMb}MB).",
            ], 413);
        }

        // Validación básica de imagen (tolerante con HEIC/HEIF en hosting)
        $mime = $file->getMimeType();
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];

        $looksLikeImage = ($mime && str_starts_with($mime, 'image/')) || in_array($ext, $allowedExtensions, true);
        if (!$looksLikeImage) {
            return response()->json([
                'error' => 'El archivo debe ser una imagen (jpg, png, webp, gif, heic).',
            ], 422);
        }

        $base = 'archivos/institucion' . $session->institucion_id;
        $path = $session->status === 'PENDING_PAGOS'
            ? ($base . '/pagoslcch/pagosunicosgestiones')
            : ($base . '/FotosPerfiles');

        $fullDir = public_path($path);

        try {
            if (!File::exists($fullDir)) {
                File::makeDirectory($fullDir, 0755, true, true);
            }

            if (!is_dir($fullDir) || !is_writable($fullDir)) {
                return response()->json([
                    'error' => 'El servidor no tiene permisos para guardar la imagen. Verifica permisos de escritura en la carpeta de destino.',
                ], 500);
            }

            $safeName = time() . '_' . Str::random(8) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
            $file->move($fullDir, $safeName);
        } catch (\Throwable $e) {
            Log::error('CaptureSession upload failed', [
                'token' => $token,
                'institucion_id' => $session->institucion_id,
                'path' => $path,
                'fullDir' => $fullDir,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'No se pudo guardar la imagen en el servidor (error interno). En hosting suele ser permisos de escritura o falta de espacio.',
            ], 500);
        }

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

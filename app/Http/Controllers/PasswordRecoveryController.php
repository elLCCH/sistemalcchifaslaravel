<?php

namespace App\Http\Controllers;

use App\Models\Estudiantesifas;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use App\Models\Usuarioslcchs;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class PasswordRecoveryController extends Controller
{
    private function limiterTooMany(string $key, int $maxAttempts, int $decaySeconds): array
    {
        $cache = Cache::store('file');

        $countKey = 'pwdrec:' . $key . ':count';
        $untilKey = 'pwdrec:' . $key . ':until';

        $until = (int) ($cache->get($untilKey, 0) ?? 0);
        $now = time();

        // Ventana activa
        if ($until <= 0 || $until <= $now) {
            $until = $now + $decaySeconds;
            $cache->put($untilKey, $until, $decaySeconds);
            $cache->put($countKey, 0, $decaySeconds);
        }

        $count = (int) ($cache->get($countKey, 0) ?? 0);
        $remainingSeconds = max(0, $until - $now);

        return [
            'too_many' => $count >= $maxAttempts,
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    private function limiterHit(string $key, int $decaySeconds): void
    {
        $cache = Cache::store('file');
        $countKey = 'pwdrec:' . $key . ':count';
        $untilKey = 'pwdrec:' . $key . ':until';

        $until = (int) ($cache->get($untilKey, 0) ?? 0);
        $now = time();
        if ($until <= 0 || $until <= $now) {
            $until = $now + $decaySeconds;
            $cache->put($untilKey, $until, $decaySeconds);
            $cache->put($countKey, 0, $decaySeconds);
        }

        // increment no extiende TTL en file store, pero el TTL ya está en ambas keys
        if (!$cache->has($countKey)) {
            $cache->put($countKey, 0, max(1, $until - $now));
        }
        try {
            $cache->increment($countKey);
        } catch (\Throwable $e) {
            $current = (int) ($cache->get($countKey, 0) ?? 0);
            $cache->put($countKey, $current + 1, max(1, $until - $now));
        }
    }

    private function normalizePertenencia(?string $pertenencia): ?string
    {
        if ($pertenencia === null) {
            return null;
        }

        $p = strtolower(trim($pertenencia));
        return match ($p) {
            'usuarioslcchs', 'lcchs', 'superlcchs', 'super' => 'usuarioslcchs',
            'planteladministrativos', 'administrativos', 'admins' => 'planteladministrativos',
            'planteldocentes', 'docentes' => 'planteldocentes',
            'estudiantesifas', 'estudiantes', 'ifa' => 'estudiantesifas',
            default => null,
        };
    }

    private function findUser(string $usuario, ?string $pertenencia)
    {
        $pertenencia = $this->normalizePertenencia($pertenencia);

        $buscar = function (string $tableKey) use ($usuario) {
            return match ($tableKey) {
                'usuarioslcchs' => Usuarioslcchs::where('Usuario', $usuario)->first(),
                'planteladministrativos' => Planteladministrativos::where('Usuario', $usuario)->first(),
                'planteldocentes' => Planteldocentes::where('Usuario', $usuario)->first(),
                'estudiantesifas' => Estudiantesifas::where('Usuario', $usuario)->first(),
                default => null,
            };
        };

        if ($pertenencia) {
            return [$buscar($pertenencia), $pertenencia];
        }

        $candidates = [];
        foreach (['usuarioslcchs', 'planteladministrativos', 'planteldocentes', 'estudiantesifas'] as $key) {
            $u = $buscar($key);
            if ($u) {
                $candidates[] = [$u, $key];
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if (count($candidates) > 1) {
            return [null, null, 'Usuario duplicado en múltiples tablas. Indica pertenencia.'];
        }

        return [null, null];
    }

    public function start(Request $request)
    {
        $request->validate([
            'Usuario' => ['required', 'string', 'max:50'],
            'pertenencia' => ['nullable', 'string', 'max:50'],
        ]);

        $usuario = strtoupper(trim((string) $request->input('Usuario')));
        $pertenencia = $request->input('pertenencia');

        // Rate limit suave para enumeración de usuarios: 20/min por IP
        $rateKey = sha1('start|' . (string) $request->ip());
        $limit = $this->limiterTooMany($rateKey, 20, 60);
        if ($limit['too_many']) {
            return response()->json([
                'message' => 'Demasiados intentos. Intenta nuevamente en ' . $limit['remaining_seconds'] . 's.',
            ], 429);
        }
        $this->limiterHit($rateKey, 60);

        [$user, $tipo, $err] = array_pad($this->findUser($usuario, $pertenencia), 3, null);

        if ($err) {
            return response()->json(['message' => $err], 409);
        }

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if (($user->Estado ?? null) !== 'ACTIVO') {
            return response()->json(['message' => 'Usuario no activo.'], 403);
        }

        if (!(bool) ($user->google2fa_enabled ?? false) || empty($user->google2fa_secret)) {
            return response()->json([
                'message' => 'Este usuario no tiene Google Authenticator vinculado. Contacta a soporte.',
            ], 409);
        }

        return response()->json([
            'message' => 'OK. Ingresa tu código de Google Authenticator para cambiar la contraseña.',
            'pertenencia' => $tipo,
        ]);
    }

    public function reset(Request $request, TwoFactorService $twoFactor)
    {
        $request->validate([
            'Usuario' => ['required', 'string', 'max:50'],
            'pertenencia' => ['nullable', 'string', 'max:50'],
            'code' => ['required', 'string', 'min:4', 'max:12'],
            'new_password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $usuario = strtoupper(trim((string) $request->input('Usuario')));
        $pertenencia = $request->input('pertenencia');

        // Rate limit fuerte para evitar fuerza bruta de TOTP: 5/min por usuario+IP
        $rateKey = sha1('reset|' . $usuario . '|' . (string) $request->ip());
        $limit = $this->limiterTooMany($rateKey, 5, 60);
        if ($limit['too_many']) {
            return response()->json([
                'message' => 'Demasiados intentos. Intenta nuevamente en ' . $limit['remaining_seconds'] . 's.',
            ], 429);
        }
        $this->limiterHit($rateKey, 60);

        [$user, $tipo, $err] = array_pad($this->findUser($usuario, $pertenencia), 3, null);

        if ($err) {
            return response()->json(['message' => $err], 409);
        }

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if (($user->Estado ?? null) !== 'ACTIVO') {
            return response()->json(['message' => 'Usuario no activo.'], 403);
        }

        if (!(bool) ($user->google2fa_enabled ?? false) || empty($user->google2fa_secret)) {
            return response()->json([
                'message' => 'Este usuario no tiene Google Authenticator vinculado.',
            ], 409);
        }

        $secret = $twoFactor->decryptSecret($user->google2fa_secret);
        if (!$secret || !$twoFactor->verifyCode($secret, $request->input('code'))) {
            return response()->json(['message' => 'Código inválido.'], 422);
        }

        $user->Contrasenia = Hash::make((string) $request->input('new_password'));
        $user->save();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
            'pertenencia' => $tipo,
        ]);
    }
}

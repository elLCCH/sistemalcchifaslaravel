<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorService;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function status(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        return response()->json([
            'enabled' => (bool) ($user->google2fa_enabled ?? false),
            'confirmed_at' => $user->google2fa_confirmed_at ?? null,
        ]);
    }

    public function enrollStart(Request $request, TwoFactorService $twoFactor)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if ((bool) ($user->google2fa_enabled ?? false)) {
            return response()->json([
                'message' => 'Ya tienes Google Authenticator vinculado. Primero desvincula para volver a vincular.',
            ], 409);
        }

        $secret = $twoFactor->generateSecret();
        $user->google2fa_secret = $twoFactor->encryptSecret($secret);
        $user->google2fa_enabled = false;
        $user->google2fa_confirmed_at = null;
        $user->save();

        $issuer = (string) (config('app.name') ?: 'SISTEMA');
        $accountName = (string) ($user->Usuario ?? $user->usuario ?? ('user-' . $user->id));

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $twoFactor->otpAuthUrl($issuer, $accountName, $secret),
            'issuer' => $issuer,
            'account' => $accountName,
        ]);
    }

    public function enrollConfirm(Request $request, TwoFactorService $twoFactor)
    {
        $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:12'],
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $secret = $twoFactor->decryptSecret($user->google2fa_secret ?? null);
        if (!$secret) {
            return response()->json([
                'message' => 'No hay un secreto pendiente. Genera el QR nuevamente.',
            ], 409);
        }

        if (!$twoFactor->verifyCode($secret, $request->input('code'))) {
            return response()->json([
                'message' => 'Código inválido. Verifica la hora del celular y vuelve a intentar.',
            ], 422);
        }

        $user->google2fa_enabled = true;
        $user->google2fa_confirmed_at = now();
        $user->save();

        return response()->json([
            'message' => 'Google Authenticator vinculado correctamente.',
            'enabled' => true,
            'confirmed_at' => $user->google2fa_confirmed_at,
        ]);
    }

    public function disable(Request $request, TwoFactorService $twoFactor)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $enabled = (bool) ($user->google2fa_enabled ?? false);
        if ($enabled) {
            $request->validate([
                'code' => ['required', 'string', 'min:4', 'max:12'],
            ]);

            $secret = $twoFactor->decryptSecret($user->google2fa_secret ?? null);
            if (!$secret || !$twoFactor->verifyCode($secret, $request->input('code'))) {
                return response()->json([
                    'message' => 'Código inválido. No se pudo desvincular.',
                ], 422);
            }
        }

        $user->google2fa_secret = null;
        $user->google2fa_enabled = false;
        $user->google2fa_confirmed_at = null;
        $user->save();

        return response()->json([
            'message' => 'Google Authenticator desvinculado.',
            'enabled' => false,
        ]);
    }
}

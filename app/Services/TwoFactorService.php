<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function generateSecret(int $length = 32): string
    {
        return $this->google2fa->generateSecretKey($length);
    }

    public function encryptSecret(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    public function decryptSecret(?string $encrypted): ?string
    {
        if (!$encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function normalizeCode(?string $code): string
    {
        $code = (string) ($code ?? '');
        $code = trim($code);
        $code = str_replace([' ', '-'], '', $code);
        return $code;
    }

    public function verifyCode(string $secret, ?string $code, int $window = 1): bool
    {
        $normalized = $this->normalizeCode($code);
        if ($normalized === '') {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($secret, $normalized, $window);
    }

    public function otpAuthUrl(string $issuer, string $accountName, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $accountName, $secret);
    }
}
